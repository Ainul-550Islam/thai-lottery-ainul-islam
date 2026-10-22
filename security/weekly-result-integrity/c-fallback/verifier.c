/*
 * Stdin/stdout native binary implementation of weekly-result-integrity.
 *
 * Implements the byte-identical WKLY1 canonicalizer, field validator, SHA-256
 * fingerprinter, and libsodium Ed25519 signature verifier when building in
 * air-gapped / offline container environments where rustc/cargo are absent.
 */
#define _POSIX_C_SOURCE 200809L

#include <ctype.h>
#include <dlfcn.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define MAX_INPUT_BYTES (64 * 1024)

typedef int (*sodium_init_fn)(void);
typedef int (*crypto_hash_sha256_fn)(unsigned char *out, const unsigned char *in, unsigned long long inlen);
typedef int (*crypto_sign_verify_detached_fn)(const unsigned char *sig, const unsigned char *m, unsigned long long mlen, const unsigned char *pk);

static crypto_hash_sha256_fn fn_sha256 = NULL;
static crypto_sign_verify_detached_fn fn_ed25519_verify = NULL;

static bool load_sodium(void) {
    const char *candidates[] = {
        "libsodium.so.23",
        "/usr/lib/x86_64-linux-gnu/libsodium.so.23",
        "/usr/lib/aarch64-linux-gnu/libsodium.so.23",
        "libsodium.so",
        NULL
    };
    void *handle = NULL;
    for (size_t i = 0; candidates[i] != NULL; i++) {
        handle = dlopen(candidates[i], RTLD_NOW | RTLD_LOCAL);
        if (handle != NULL) {
            break;
        }
    }
    if (handle == NULL) {
        return false;
    }
    sodium_init_fn init_fn = NULL;
    *(void **)(&init_fn) = dlsym(handle, "sodium_init");
    *(void **)(&fn_sha256) = dlsym(handle, "crypto_hash_sha256");
    *(void **)(&fn_ed25519_verify) = dlsym(handle, "crypto_sign_verify_detached");
    if (init_fn == NULL || fn_sha256 == NULL || fn_ed25519_verify == NULL) {
        return false;
    }
    return init_fn() >= 0;
}

static bool is_valid_utf8(const unsigned char *s, size_t len) {
    size_t i = 0;
    while (i < len) {
        unsigned char c = s[i];
        if (c <= 0x7F) {
            i += 1;
        } else if (c >= 0xC2 && c <= 0xDF) {
            if (i + 1 >= len || (s[i + 1] & 0xC0) != 0x80) return false;
            i += 2;
        } else if (c == 0xE0) {
            if (i + 2 >= len || s[i + 1] < 0xA0 || s[i + 1] > 0xBF || (s[i + 2] & 0xC0) != 0x80) return false;
            i += 3;
        } else if ((c >= 0xE1 && c <= 0xEC) || (c >= 0xEE && c <= 0xEF)) {
            if (i + 2 >= len || (s[i + 1] & 0xC0) != 0x80 || (s[i + 2] & 0xC0) != 0x80) return false;
            i += 3;
        } else if (c == 0xED) {
            if (i + 2 >= len || s[i + 1] < 0x80 || s[i + 1] > 0x9F || (s[i + 2] & 0xC0) != 0x80) return false;
            i += 3;
        } else if (c == 0xF0) {
            if (i + 3 >= len || s[i + 1] < 0x90 || s[i + 1] > 0xBF || (s[i + 2] & 0xC0) != 0x80 || (s[i + 3] & 0xC0) != 0x80) return false;
            i += 4;
        } else if (c >= 0xF1 && c <= 0xF3) {
            if (i + 3 >= len || (s[i + 1] & 0xC0) != 0x80 || (s[i + 2] & 0xC0) != 0x80 || (s[i + 3] & 0xC0) != 0x80) return false;
            i += 4;
        } else if (c == 0xF4) {
            if (i + 3 >= len || s[i + 1] < 0x80 || s[i + 1] > 0x8F || (s[i + 2] & 0xC0) != 0x80 || (s[i + 3] & 0xC0) != 0x80) return false;
            i += 4;
        } else {
            return false;
        }
    }
    return true;
}

static bool has_unicode_control(const unsigned char *s, size_t len) {
    size_t i = 0;
    while (i < len) {
        unsigned char c = s[i];
        if (c < 0x20 || c == 0x7F) {
            return true;
        }
        /* U+0080..U+009F in UTF-8 is 0xC2 0x80..0x9F */
        if (c == 0xC2 && i + 1 < len && s[i + 1] >= 0x80 && s[i + 1] <= 0x9F) {
            return true;
        }
        i += 1;
    }
    return false;
}

/* Minimal strict RFC 8259 flat object parser matching serde_json VerificationRequest */
typedef struct {
    bool present;
    bool is_null;
    char *value;
    size_t len;
} JsonField;

typedef struct {
    JsonField draw_reference;
    JsonField draw_date;
    JsonField first_6;
    JsonField three_ball;
    JsonField two_ball;
    JsonField source_identifier;
    JsonField parser_version;
    JsonField expected_fingerprint;
    JsonField public_key_hex;
    JsonField signature_hex;
} RequestDoc;

static void skip_ws(const char **p) {
    while (**p == ' ' || **p == '\t' || **p == '\n' || **p == '\r') {
        (*p)++;
    }
}

static bool append_utf8_cp(char **buf, size_t *len, size_t *cap, uint32_t cp) {
    if (*len + 5 >= *cap) {
        *cap *= 2;
        char *nb = realloc(*buf, *cap);
        if (!nb) return false;
        *buf = nb;
    }
    if (cp <= 0x7F) {
        (*buf)[(*len)++] = (char)cp;
    } else if (cp <= 0x7FF) {
        (*buf)[(*len)++] = (char)(0xC0 | (cp >> 6));
        (*buf)[(*len)++] = (char)(0x80 | (cp & 0x3F));
    } else if (cp <= 0xFFFF) {
        if (cp >= 0xD800 && cp <= 0xDFFF) return false;
        (*buf)[(*len)++] = (char)(0xE0 | (cp >> 12));
        (*buf)[(*len)++] = (char)(0x80 | ((cp >> 6) & 0x3F));
        (*buf)[(*len)++] = (char)(0x80 | (cp & 0x3F));
    } else if (cp <= 0x10FFFF) {
        (*buf)[(*len)++] = (char)(0xF0 | (cp >> 18));
        (*buf)[(*len)++] = (char)(0x80 | ((cp >> 12) & 0x3F));
        (*buf)[(*len)++] = (char)(0x80 | ((cp >> 6) & 0x3F));
        (*buf)[(*len)++] = (char)(0x80 | (cp & 0x3F));
    } else {
        return false;
    }
    return true;
}

static int hex_nibble(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

static bool parse_u16_hex(const char **p, uint16_t *out) {
    uint16_t v = 0;
    for (int i = 0; i < 4; i++) {
        int n = hex_nibble(**p);
        if (n < 0) return false;
        v = (uint16_t)((v << 4) | (uint16_t)n);
        (*p)++;
    }
    *out = v;
    return true;
}

static bool parse_json_string(const char **p, char **out_str, size_t *out_len) {
    if (**p != '"') return false;
    (*p)++;
    size_t cap = 64;
    size_t len = 0;
    char *buf = malloc(cap);
    if (!buf) return false;

    while (**p != '\0' && **p != '"') {
        unsigned char c = (unsigned char)**p;
        if (c < 0x20) {
            free(buf);
            return false;
        }
        if (c == '\\') {
            (*p)++;
            char esc = **p;
            if (esc == '\0') { free(buf); return false; }
            (*p)++;
            if (esc == '"' || esc == '\\' || esc == '/') {
                if (!append_utf8_cp(&buf, &len, &cap, (uint32_t)esc)) { free(buf); return false; }
            } else if (esc == 'b') {
                if (!append_utf8_cp(&buf, &len, &cap, '\b')) { free(buf); return false; }
            } else if (esc == 'f') {
                if (!append_utf8_cp(&buf, &len, &cap, '\f')) { free(buf); return false; }
            } else if (esc == 'n') {
                if (!append_utf8_cp(&buf, &len, &cap, '\n')) { free(buf); return false; }
            } else if (esc == 'r') {
                if (!append_utf8_cp(&buf, &len, &cap, '\r')) { free(buf); return false; }
            } else if (esc == 't') {
                if (!append_utf8_cp(&buf, &len, &cap, '\t')) { free(buf); return false; }
            } else if (esc == 'u') {
                uint16_t u1 = 0;
                if (!parse_u16_hex(p, &u1)) { free(buf); return false; }
                uint32_t cp = u1;
                if (u1 >= 0xD800 && u1 <= 0xDBFF) {
                    if ((*p)[0] != '\\' || (*p)[1] != 'u') { free(buf); return false; }
                    *p += 2;
                    uint16_t u2 = 0;
                    if (!parse_u16_hex(p, &u2) || u2 < 0xDC00 || u2 > 0xDFFF) { free(buf); return false; }
                    cp = 0x10000U + (((uint32_t)(u1 - 0xD800) << 10) | (uint32_t)(u2 - 0xDC00));
                }
                if (!append_utf8_cp(&buf, &len, &cap, cp)) { free(buf); return false; }
            } else {
                free(buf);
                return false;
            }
        } else {
            if (len + 2 >= cap) {
                cap *= 2;
                char *nb = realloc(buf, cap);
                if (!nb) { free(buf); return false; }
                buf = nb;
            }
            buf[len++] = (char)c;
            (*p)++;
        }
    }
    if (**p != '"') {
        free(buf);
        return false;
    }
    (*p)++;
    buf[len] = '\0';
    *out_str = buf;
    *out_len = len;
    return true;
}

static bool skip_json_value(const char **p, int depth) {
    if (depth > 32) return false;
    skip_ws(p);
    if (**p == '"') {
        char *s = NULL;
        size_t l = 0;
        if (!parse_json_string(p, &s, &l)) return false;
        free(s);
        return true;
    }
    if (strncmp(*p, "true", 4) == 0) { *p += 4; return true; }
    if (strncmp(*p, "false", 5) == 0) { *p += 5; return true; }
    if (strncmp(*p, "null", 4) == 0) { *p += 4; return true; }
    if (**p == '-' || isdigit((unsigned char)**p)) {
        if (**p == '-') (*p)++;
        if (!isdigit((unsigned char)**p)) return false;
        while (isdigit((unsigned char)**p)) (*p)++;
        if (**p == '.') {
            (*p)++;
            if (!isdigit((unsigned char)**p)) return false;
            while (isdigit((unsigned char)**p)) (*p)++;
        }
        if (**p == 'e' || **p == 'E') {
            (*p)++;
            if (**p == '+' || **p == '-') (*p)++;
            if (!isdigit((unsigned char)**p)) return false;
            while (isdigit((unsigned char)**p)) (*p)++;
        }
        return true;
    }
    if (**p == '[') {
        (*p)++;
        skip_ws(p);
        if (**p == ']') { (*p)++; return true; }
        while (true) {
            if (!skip_json_value(p, depth + 1)) return false;
            skip_ws(p);
            if (**p == ']') { (*p)++; return true; }
            if (**p != ',') return false;
            (*p)++;
        }
    }
    if (**p == '{') {
        (*p)++;
        skip_ws(p);
        if (**p == '}') { (*p)++; return true; }
        while (true) {
            skip_ws(p);
            char *k = NULL; size_t kl = 0;
            if (!parse_json_string(p, &k, &kl)) return false;
            free(k);
            skip_ws(p);
            if (**p != ':') return false;
            (*p)++;
            if (!skip_json_value(p, depth + 1)) return false;
            skip_ws(p);
            if (**p == '}') { (*p)++; return true; }
            if (**p != ',') return false;
            (*p)++;
        }
    }
    return false;
}

static bool assign_field(JsonField *target, const char **p, bool allow_null) {
    if (target->present) {
        /* serde_json duplicate field fails deserialization */
        return false;
    }
    skip_ws(p);
    if (allow_null && strncmp(*p, "null", 4) == 0) {
        *p += 4;
        target->present = true;
        target->is_null = true;
        target->value = NULL;
        target->len = 0;
        return true;
    }
    char *val = NULL;
    size_t vlen = 0;
    if (!parse_json_string(p, &val, &vlen)) {
        return false;
    }
    target->present = true;
    target->is_null = false;
    target->value = val;
    target->len = vlen;
    return true;
}

static bool parse_request_doc(const char *input, RequestDoc *doc) {
    memset(doc, 0, sizeof(*doc));
    const char *p = input;
    skip_ws(&p);
    if (*p != '{') return false;
    p++;
    skip_ws(&p);
    if (*p != '}') {
        while (true) {
            skip_ws(&p);
            char *key = NULL;
            size_t klen = 0;
            if (!parse_json_string(&p, &key, &klen)) return false;
            skip_ws(&p);
            if (*p != ':') { free(key); return false; }
            p++;

            bool ok = false;
            if (strcmp(key, "draw_reference") == 0) ok = assign_field(&doc->draw_reference, &p, false);
            else if (strcmp(key, "draw_date") == 0) ok = assign_field(&doc->draw_date, &p, false);
            else if (strcmp(key, "first_6") == 0) ok = assign_field(&doc->first_6, &p, true);
            else if (strcmp(key, "three_ball") == 0) ok = assign_field(&doc->three_ball, &p, true);
            else if (strcmp(key, "two_ball") == 0) ok = assign_field(&doc->two_ball, &p, true);
            else if (strcmp(key, "source_identifier") == 0) ok = assign_field(&doc->source_identifier, &p, true);
            else if (strcmp(key, "parser_version") == 0) ok = assign_field(&doc->parser_version, &p, false);
            else if (strcmp(key, "expected_fingerprint") == 0) ok = assign_field(&doc->expected_fingerprint, &p, true);
            else if (strcmp(key, "public_key_hex") == 0) ok = assign_field(&doc->public_key_hex, &p, true);
            else if (strcmp(key, "signature_hex") == 0) ok = assign_field(&doc->signature_hex, &p, true);
            else ok = skip_json_value(&p, 1);

            free(key);
            if (!ok) return false;

            skip_ws(&p);
            if (*p == '}') { p++; break; }
            if (*p != ',') return false;
            p++;
        }
    }
    skip_ws(&p);
    if (*p != '\0') return false;
    if (!doc->draw_reference.present || !doc->draw_date.present || !doc->parser_version.present) {
        return false;
    }
    return true;
}

static void emit_report(
    const char *status,
    bool acceptable,
    const char *fingerprint,
    long canonical_len,
    const char *error_code,
    const char *error_field
) {
    printf("{\"status\":\"%s\",\"acceptable\":%s,\"canonical_version\":\"WKLY1\",",
           status, acceptable ? "true" : "false");
    if (fingerprint != NULL) printf("\"fingerprint\":\"%s\",", fingerprint);
    else printf("\"fingerprint\":null,");
    if (canonical_len >= 0) printf("\"canonical_length\":%ld,", canonical_len);
    else printf("\"canonical_length\":null,");
    if (error_code != NULL) printf("\"error_code\":\"%s\",", error_code);
    else printf("\"error_code\":null,");
    if (error_field != NULL) printf("\"error_field\":\"%s\"}\n", error_field);
    else printf("\"error_field\":null}\n");
    fflush(stdout);
}

static bool is_iso_date(const char *s, size_t len) {
    if (len != 10) return false;
    for (size_t i = 0; i < 10; i++) {
        if (i == 4 || i == 7) {
            if (s[i] != '-') return false;
        } else {
            if (!isdigit((unsigned char)s[i])) return false;
        }
    }
    int month = (s[5] - '0') * 10 + (s[6] - '0');
    int day = (s[8] - '0') * 10 + (s[9] - '0');
    return (month >= 1 && month <= 12) && (day >= 1 && day <= 31);
}

static bool check_digits(const JsonField *f, size_t width) {
    if (!f->present || f->is_null) return true;
    if (f->len != width) return false;
    for (size_t i = 0; i < width; i++) {
        if (!isdigit((unsigned char)f->value[i])) return false;
    }
    return true;
}

static bool hex_decode_exact(const char *hex, size_t expected_raw_len, unsigned char *out) {
    size_t hlen = strlen(hex);
    if (hlen != expected_raw_len * 2) return false;
    for (size_t i = 0; i < expected_raw_len; i++) {
        int hi = hex_nibble(hex[i * 2]);
        int lo = hex_nibble(hex[i * 2 + 1]);
        if (hi < 0 || lo < 0) return false;
        out[i] = (unsigned char)((hi << 4) | lo);
    }
    return true;
}

int main(void) {
    if (!load_sodium()) {
        printf("{\"status\":\"REJECTED\",\"acceptable\":false,\"error_code\":\"MALFORMED_REQUEST\"}\n");
        fflush(stdout);
        return 1;
    }

    size_t cap = MAX_INPUT_BYTES + 2;
    unsigned char *buf = malloc(cap);
    if (!buf) {
        printf("{\"status\":\"REJECTED\",\"acceptable\":false,\"error_code\":\"MALFORMED_REQUEST\"}\n");
        fflush(stdout);
        return 1;
    }

    size_t total = 0;
    while (total < MAX_INPUT_BYTES + 1) {
        size_t n = fread(buf + total, 1, (MAX_INPUT_BYTES + 1) - total, stdin);
        total += n;
        if (n == 0) {
            if (ferror(stdin)) {
                free(buf);
                printf("{\"status\":\"REJECTED\",\"acceptable\":false,\"error_code\":\"MALFORMED_REQUEST\"}\n");
                fflush(stdout);
                return 1;
            }
            break;
        }
    }

    if (total > MAX_INPUT_BYTES) {
        free(buf);
        printf("{\"status\":\"REJECTED\",\"acceptable\":false,\"error_code\":\"DOCUMENT_TOO_LARGE\"}\n");
        fflush(stdout);
        return 1;
    }
    buf[total] = '\0';

    if (!is_valid_utf8(buf, total) || memchr(buf, '\0', total) != NULL) {
        free(buf);
        printf("{\"status\":\"REJECTED\",\"acceptable\":false,\"error_code\":\"INVALID_UTF8\"}\n");
        fflush(stdout);
        return 1;
    }

    RequestDoc doc;
    if (!parse_request_doc((const char *)buf, &doc)) {
        free(buf);
        emit_report("REJECTED", false, NULL, -1, "MALFORMED_REQUEST", NULL);
        return 1;
    }
    free(buf);

    /* Validate fields in exact canonical.rs order */
    if (doc.draw_reference.len == 0 || doc.draw_reference.len > 64) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "draw_reference");
        return 2;
    }
    for (size_t i = 0; i < doc.draw_reference.len; i++) {
        unsigned char c = (unsigned char)doc.draw_reference.value[i];
        if (!(isalnum(c) || c == '-' || c == '_')) {
            emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "draw_reference");
            return 2;
        }
    }

    if (!is_iso_date(doc.draw_date.value, doc.draw_date.len)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "draw_date");
        return 2;
    }

    if (!check_digits(&doc.first_6, 6)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "first_6");
        return 2;
    }
    if (!check_digits(&doc.three_ball, 3)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "three_ball");
        return 2;
    }
    if (!check_digits(&doc.two_ball, 2)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "two_ball");
        return 2;
    }

    bool p6 = doc.first_6.present && !doc.first_6.is_null;
    bool p3 = doc.three_ball.present && !doc.three_ball.is_null;
    bool p2 = doc.two_ball.present && !doc.two_ball.is_null;
    if ((p6 || p3 || p2) && !(p6 && p3 && p2)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "result_triple");
        return 2;
    }

    if (doc.source_identifier.present && !doc.source_identifier.is_null) {
        if (doc.source_identifier.len > 191 ||
            has_unicode_control((const unsigned char *)doc.source_identifier.value, doc.source_identifier.len)) {
            emit_report("REJECTED", false, NULL, -1, "INVALID_ENCODING", "source_identifier");
            return 2;
        }
    }

    if (doc.parser_version.len == 0 || doc.parser_version.len > 16 ||
        has_unicode_control((const unsigned char *)doc.parser_version.value, doc.parser_version.len)) {
        emit_report("REJECTED", false, NULL, -1, "INVALID_FIELD", "parser_version");
        return 2;
    }

    /* Build canonical byte buffer */
    const char *names[7] = {
        "draw_reference", "draw_date", "first_6", "three_ball",
        "two_ball", "source_identifier", "parser_version"
    };
    const JsonField *fields[7] = {
        &doc.draw_reference, &doc.draw_date, &doc.first_6, &doc.three_ball,
        &doc.two_ball, &doc.source_identifier, &doc.parser_version
    };

    unsigned char canonical[2048];
    size_t clen = (size_t)snprintf((char *)canonical, sizeof(canonical), "WKLY1\n7\n");
    for (int i = 0; i < 7; i++) {
        size_t nlen = strlen(names[i]);
        clen += (size_t)snprintf((char *)canonical + clen, sizeof(canonical) - clen, "%zu:%s=", nlen, names[i]);
        if (fields[i]->present && !fields[i]->is_null) {
            clen += (size_t)snprintf((char *)canonical + clen, sizeof(canonical) - clen, "S%zu:", fields[i]->len);
            memcpy(canonical + clen, fields[i]->value, fields[i]->len);
            clen += fields[i]->len;
        } else {
            memcpy(canonical + clen, "N0:", 3);
            clen += 3;
        }
        canonical[clen++] = '\n';
    }

    unsigned char hash_bin[32];
    fn_sha256(hash_bin, canonical, (unsigned long long)clen);
    char fingerprint[65];
    for (int i = 0; i < 32; i++) {
        snprintf(fingerprint + i * 2, 3, "%02x", hash_bin[i]);
    }
    fingerprint[64] = '\0';

    if (doc.expected_fingerprint.present && !doc.expected_fingerprint.is_null) {
        bool eq = (doc.expected_fingerprint.len == 64);
        unsigned char diff = 0;
        if (eq) {
            for (size_t i = 0; i < 64; i++) {
                diff |= (unsigned char)doc.expected_fingerprint.value[i] ^ (unsigned char)fingerprint[i];
            }
        }
        if (!eq || diff != 0) {
            emit_report("FINGERPRINT_MISMATCH", false, fingerprint, (long)clen, NULL, NULL);
            return 2;
        }
    }

    bool has_pk = doc.public_key_hex.present && !doc.public_key_hex.is_null;
    bool has_sig = doc.signature_hex.present && !doc.signature_hex.is_null;

    if (!has_pk && !has_sig) {
        emit_report("INTEGRITY_HASH_ONLY", true, fingerprint, (long)clen, NULL, NULL);
        return 0;
    }

    if (has_pk != has_sig) {
        emit_report("SIGNATURE_INVALID", false, fingerprint, (long)clen, "INCOMPLETE_SIGNATURE_MATERIAL", NULL);
        return 2;
    }

    unsigned char pk[32];
    if (!hex_decode_exact(doc.public_key_hex.value, 32, pk)) {
        emit_report("SIGNATURE_INVALID", false, fingerprint, (long)clen, "INVALID_PUBLIC_KEY", NULL);
        return 2;
    }

    unsigned char sig[64];
    if (!hex_decode_exact(doc.signature_hex.value, 64, sig)) {
        emit_report("SIGNATURE_INVALID", false, fingerprint, (long)clen, "INVALID_SIGNATURE_ENCODING", NULL);
        return 2;
    }

    if (fn_ed25519_verify(sig, canonical, (unsigned long long)clen, pk) == 0) {
        emit_report("SIGNED_VERIFIED", true, fingerprint, (long)clen, NULL, NULL);
        return 0;
    }

    emit_report("SIGNATURE_INVALID", false, fingerprint, (long)clen, "SIGNATURE_MISMATCH", NULL);
    return 2;
}
