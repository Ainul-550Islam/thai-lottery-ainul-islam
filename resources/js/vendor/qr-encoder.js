/**
 * Self-contained QR Code encoder (byte mode).
 *
 * ============================================================================
 * WHY THIS IS VENDORED RATHER THAN IMPORTED
 * ============================================================================
 * resources/js/deposit-portal.js used to `import QRCode from 'qrcode'`. That
 * package is not in package.json and not in package-lock.json, so the deposit
 * page's chunk could not be resolved by Vite at all: `npm run build` fails on
 * the import, which means the whole asset graph fails, which means the deposit
 * page ships no JavaScript. A QR encoder was needed and no dependency could be
 * added, so the encoder lives here.
 *
 * ============================================================================
 * WHAT IT IMPLEMENTS, AND WHAT IT DOES NOT
 * ============================================================================
 * Implements ISO/IEC 18004 byte mode (8-bit, UTF-8 payloads up to 834 bytes at
 * ECC level M), versions 1–20, all four ECC levels, the eight data masks with
 * the standard penalty scoring, format information, and version information for
 * version 7 and above.
 *
 * Does NOT implement the numeric/alphanumeric/Kanji modes, ECI, structured
 * append, or micro QR. None of them are reachable from here: the only caller
 * encodes a PromptPay EMV payload, which is printable ASCII.
 *
 * ============================================================================
 * THE ONE INVARIANT THAT MATTERS
 * ============================================================================
 * This code produces a picture of the payload it was given. It never invents,
 * normalises, rounds or truncates that payload — a QR that scans to something
 * other than the authenticated payload would send money to the wrong place. The
 * caller passes the string the server returned, and the encoder encodes exactly
 * that string or throws.
 */

/** L = 0, M = 1, Q = 2, H = 3 — the ISO ordering, used as table indices. */
const ECL_INDEX = { L: 0, M: 1, Q: 2, H: 3 };

/** Format-information bit pattern per ECC level (not the same as the index). */
const ECL_FORMAT_BITS = { L: 1, M: 0, Q: 3, H: 2 };

/** How many error-correction codewords each block carries, per version. */
const ECC_CODEWORDS_PER_BLOCK = [
    [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
    [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
    [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
    [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28],
];

/** How many error-correction blocks the data is split into, per version. */
const NUM_ECC_BLOCKS = [
    [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
    [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
    [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
    [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25],
];

const MIN_VERSION = 1;
const MAX_VERSION = 20;

/** Total modules across the symbol for a version (side length). */
function sizeForVersion(version) {
    return version * 4 + 17;
}

/** Byte-mode capacity in codewords, before ECC and padding, for a version. */
function dataCodewords(version, ecl) {
    const index = ECL_INDEX[ecl];
    const raw = Math.floor(rawDataModules(version) / 8);
    const eccPerBlock = ECC_CODEWORDS_PER_BLOCK[index][version];
    const blocks = NUM_ECC_BLOCKS[index][version];

    return raw - eccPerBlock * blocks;
}

/** Number of modules available for data + ECC, excluding every function pattern. */
function rawDataModules(version) {
    let result = (16 * version + 128) * version + 64;

    if (version >= 2) {
        const numAlign = Math.floor(version / 7) + 2;
        result -= (25 * numAlign - 10) * numAlign - 55;

        if (version >= 7) {
            result -= 36;
        }
    }

    return result;
}

/** Centre coordinates of the alignment patterns for a version. */
function alignmentPositions(version) {
    if (version === 1) {
        return [];
    }

    const numAlign = Math.floor(version / 7) + 2;
    // Integer division, then doubled: the alignment grid must land on the
    // canonical centres (version 7 → 6, 22, 38). Rounding up here shifts every
    // pattern by two modules and the symbol stops decoding.
    const step = version === 32
        ? 26
        : Math.floor((version * 4 + numAlign * 2 + 1) / (numAlign * 2 - 2)) * 2;

    const result = new Array(numAlign);
    result[0] = 6;

    for (let i = result.length - 1, pos = version * 4 + 10; i >= 1; i -= 1, pos -= step) {
        result[i] = pos;
    }

    return result;
}

// ── GF(256) arithmetic, primitive polynomial 0x11D ──────────────────────────

function gfMultiply(x, y) {
    let z = 0;

    for (let i = 7; i >= 0; i -= 1) {
        z = (z << 1) ^ ((z >>> 7) * 0x11D);
        z ^= ((y >>> i) & 1) * x;
    }

    return z & 0xFF;
}

/** Reed–Solomon generator polynomial of the given degree. */
function rsGenerator(degree) {
    const result = new Array(degree).fill(0);
    result[degree - 1] = 1;
    let root = 1;

    for (let i = 0; i < degree; i += 1) {
        for (let j = 0; j < result.length; j += 1) {
            result[j] = gfMultiply(result[j], root);

            if (j + 1 < result.length) {
                result[j] ^= result[j + 1];
            }
        }

        root = gfMultiply(root, 0x02);
    }

    return result;
}

/** ECC codewords for one block of data. */
function rsRemainder(data, generator) {
    const result = new Array(generator.length).fill(0);

    for (const byte of data) {
        const factor = byte ^ result.shift();
        result.push(0);

        for (let i = 0; i < result.length; i += 1) {
            result[i] ^= gfMultiply(generator[i], factor);
        }
    }

    return result;
}

// ── module matrix ──────────────────────────────────────────────────────────

class Matrix {
    constructor(version, ecl) {
        this.version = version;
        this.ecl = ecl;
        this.size = sizeForVersion(version);
        this.modules = [];
        this.isFunction = [];

        for (let y = 0; y < this.size; y += 1) {
            this.modules.push(new Array(this.size).fill(false));
            this.isFunction.push(new Array(this.size).fill(false));
        }
    }

    setFunction(x, y, dark) {
        this.modules[y][x] = dark;
        this.isFunction[y][x] = true;
    }

    drawFunctionPatterns() {
        const { size } = this;

        // Timing patterns.
        for (let i = 0; i < size; i += 1) {
            this.setFunction(6, i, i % 2 === 0);
            this.setFunction(i, 6, i % 2 === 0);
        }

        // Finder patterns and their separators, at three corners.
        this.drawFinder(3, 3);
        this.drawFinder(size - 4, 3);
        this.drawFinder(3, size - 4);

        // Alignment patterns, except where they would overlap a finder.
        const positions = alignmentPositions(this.version);

        for (let i = 0; i < positions.length; i += 1) {
            for (let j = 0; j < positions.length; j += 1) {
                const corner = (i === 0 && j === 0)
                    || (i === 0 && j === positions.length - 1)
                    || (i === positions.length - 1 && j === 0);

                if (! corner) {
                    this.drawAlignment(positions[i], positions[j]);
                }
            }
        }

        // Reserve the format areas; the real bits are written after masking.
        this.drawFormatBits('L', 0);
        this.drawVersionBits();
    }

    drawFinder(centreX, centreY) {
        for (let dy = -4; dy <= 4; dy += 1) {
            for (let dx = -4; dx <= 4; dx += 1) {
                const distance = Math.max(Math.abs(dx), Math.abs(dy));
                const x = centreX + dx;
                const y = centreY + dy;

                if (x >= 0 && x < this.size && y >= 0 && y < this.size) {
                    this.setFunction(x, y, distance !== 2 && distance !== 4);
                }
            }
        }
    }

    drawAlignment(centreX, centreY) {
        for (let dy = -2; dy <= 2; dy += 1) {
            for (let dx = -2; dx <= 2; dx += 1) {
                this.setFunction(centreX + dx, centreY + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
            }
        }
    }

    /** 15-bit BCH(15,5) format information, drawn in both reserved areas. */
    drawFormatBits(ecl, mask) {
        const data = (ECL_FORMAT_BITS[ecl] << 3) | mask;
        let remainder = data;

        for (let i = 0; i < 10; i += 1) {
            remainder = (remainder << 1) ^ ((remainder >>> 9) * 0x537);
        }

        const bits = ((data << 10) | remainder) ^ 0x5412;
        const bit = (index) => ((bits >>> index) & 1) !== 0;

        // First copy, around the top-left finder.
        for (let i = 0; i <= 5; i += 1) {
            this.setFunction(8, i, bit(i));
        }

        this.setFunction(8, 7, bit(6));
        this.setFunction(8, 8, bit(7));
        this.setFunction(7, 8, bit(8));

        for (let i = 9; i < 15; i += 1) {
            this.setFunction(14 - i, 8, bit(i));
        }

        // Second copy, split between the other two finders.
        for (let i = 0; i < 8; i += 1) {
            this.setFunction(this.size - 1 - i, 8, bit(i));
        }

        for (let i = 8; i < 15; i += 1) {
            this.setFunction(8, this.size - 15 + i, bit(i));
        }

        // The dark module, always set, at the corner of the bottom-left finder.
        this.setFunction(8, this.size - 8, true);
    }

    /** 18-bit BCH(18,6) version information, version 7 and above. */
    drawVersionBits() {
        if (this.version < 7) {
            return;
        }

        let remainder = this.version;

        for (let i = 0; i < 12; i += 1) {
            remainder = (remainder << 1) ^ ((remainder >>> 11) * 0x1F25);
        }

        const bits = (this.version << 12) | remainder;
        const bit = (index) => ((bits >>> index) & 1) !== 0;

        for (let i = 0; i < 18; i += 1) {
            const a = this.size - 11 + (i % 3);
            const b = Math.floor(i / 3);

            this.setFunction(a, b, bit(i));
            this.setFunction(b, a, bit(i));
        }
    }

    /** Write the interleaved codewords into the data area, zig-zag from bottom-right. */
    drawCodewords(data) {
        let index = 0;

        for (let right = this.size - 1; right >= 1; right -= 2) {
            if (right === 6) {
                right = 5;
            }

            for (let vertical = 0; vertical < this.size; vertical += 1) {
                const upward = ((right + 1) & 2) === 0;
                const y = upward ? this.size - 1 - vertical : vertical;

                for (let j = 0; j < 2; j += 1) {
                    const x = right - j;

                    if (this.isFunction[y][x]) {
                        continue;
                    }

                    if (index < data.length * 8) {
                        this.modules[y][x] = ((data[index >>> 3] >>> (7 - (index & 7))) & 1) !== 0;
                        index += 1;
                    }
                }
            }
        }
    }

    /** Apply one of the eight data masks (XOR) and score the result. */
    applyMask(mask, apply) {
        for (let y = 0; y < this.size; y += 1) {
            for (let x = 0; x < this.size; x += 1) {
                if (this.isFunction[y][x]) {
                    continue;
                }

                let invert;

                switch (mask) {
                    case 0: invert = (x + y) % 2 === 0; break;
                    case 1: invert = y % 2 === 0; break;
                    case 2: invert = x % 3 === 0; break;
                    case 3: invert = (x + y) % 3 === 0; break;
                    case 4: invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
                    case 5: invert = ((x * y) % 2) + ((x * y) % 3) === 0; break;
                    case 6: invert = (((x * y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                    case 7: invert = (((x + y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                    default: throw new RangeError(`unknown mask ${mask}`);
                }

                if (apply && invert) {
                    this.modules[y][x] = ! this.modules[y][x];
                }
            }
        }
    }
}

/**
 * Penalty score for a masked symbol, per ISO/IEC 18004 §8.8.2.
 *
 * The four rules penalise long runs of one colour, 2x2 blocks of one colour,
 * finder-like patterns that could confuse a scanner, and an uneven dark/light
 * balance. Choosing the lowest score is what makes the symbol easiest to read.
 */
function penaltyScore(modules) {
    const size = modules.length;
    let penalty = 0;

    // Rule 1: runs of five or more identical modules in a row or column.
    for (let y = 0; y < size; y += 1) {
        let run = 1;

        for (let x = 1; x < size; x += 1) {
            if (modules[y][x] === modules[y][x - 1]) {
                run += 1;
            } else {
                if (run >= 5) {
                    penalty += 3 + (run - 5);
                }
                run = 1;
            }
        }

        if (run >= 5) {
            penalty += 3 + (run - 5);
        }
    }

    for (let x = 0; x < size; x += 1) {
        let run = 1;

        for (let y = 1; y < size; y += 1) {
            if (modules[y][x] === modules[y - 1][x]) {
                run += 1;
            } else {
                if (run >= 5) {
                    penalty += 3 + (run - 5);
                }
                run = 1;
            }
        }

        if (run >= 5) {
            penalty += 3 + (run - 5);
        }
    }

    // Rule 2: 2x2 blocks of one colour.
    for (let y = 0; y < size - 1; y += 1) {
        for (let x = 0; x < size - 1; x += 1) {
            const topLeft = modules[y][x];

            if (topLeft === modules[y][x + 1]
                && topLeft === modules[y + 1][x]
                && topLeft === modules[y + 1][x + 1]) {
                penalty += 3;
            }
        }
    }

    // Rule 3: the 1:1:3:1:1 finder ratio with four light modules on either side.
    const finderA = [true, false, true, true, true, false, true, false, false, false, false];
    const finderB = [false, false, false, false, true, false, true, true, true, false, true];

    const matches = (line, start, pattern) => {
        for (let i = 0; i < pattern.length; i += 1) {
            if (line[start + i] !== pattern[i]) {
                return false;
            }
        }

        return true;
    };

    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x <= size - 11; x += 1) {
            if (matches(modules[y], x, finderA) || matches(modules[y], x, finderB)) {
                penalty += 40;
            }
        }
    }

    for (let x = 0; x < size; x += 1) {
        const column = [];

        for (let y = 0; y < size; y += 1) {
            column.push(modules[y][x]);
        }

        for (let y = 0; y <= size - 11; y += 1) {
            if (matches(column, y, finderA) || matches(column, y, finderB)) {
                penalty += 40;
            }
        }
    }

    // Rule 4: distance of the dark ratio from 50%, in 5% steps.
    let dark = 0;

    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x < size; x += 1) {
            if (modules[y][x]) {
                dark += 1;
            }
        }
    }

    const total = size * size;
    const ratio = Math.floor(Math.abs(dark * 20 - total * 10) / total);
    penalty += ratio * 10;

    return penalty;
}

// ── public API ─────────────────────────────────────────────────────────────

/**
 * Encode a string as a QR symbol.
 *
 * @param {string} payload the exact string to encode
 * @param {{ecl?: 'L'|'M'|'Q'|'H', mask?: number|null, minVersion?: number}} [options]
 * @returns {{size: number, version: number, ecl: string, mask: number, modules: boolean[][]}}
 */
export function encodeQr(payload, options = {}) {
    if (typeof payload !== 'string' || payload === '') {
        throw new TypeError('encodeQr requires a non-empty string payload');
    }

    const ecl = String(options.ecl || 'M').toUpperCase();

    if (! Object.prototype.hasOwnProperty.call(ECL_INDEX, ecl)) {
        throw new RangeError(`unsupported ECC level ${ecl}`);
    }

    const bytes = new TextEncoder().encode(payload);
    const eclIndex = ECL_INDEX[ecl];
    let version = Math.max(MIN_VERSION, Number(options.minVersion) || MIN_VERSION);

    // Byte mode: 4 bits of mode indicator, then a character count whose width
    // grows with the version (8 bits up to version 9, 16 bits from version 10).
    const charCountBits = (v) => (v <= 9 ? 8 : 16);

    while (version <= MAX_VERSION) {
        const capacityBits = dataCodewords(version, ecl) * 8;
        const neededBits = 4 + charCountBits(version) + bytes.length * 8;

        if (neededBits <= capacityBits) {
            break;
        }

        version += 1;
    }

    if (version > MAX_VERSION) {
        throw new RangeError(`payload of ${bytes.length} bytes does not fit at ECC level ${ecl}`);
    }

    // ── bit stream ──
    const capacityBits = dataCodewords(version, ecl) * 8;
    const bits = [];

    const pushBits = (value, length) => {
        for (let i = length - 1; i >= 0; i -= 1) {
            bits.push(((value >>> i) & 1) !== 0);
        }
    };

    pushBits(0b0100, 4); // byte mode
    pushBits(bytes.length, charCountBits(version));

    for (const byte of bytes) {
        pushBits(byte, 8);
    }

    // Terminator, then pad to a byte boundary, then the standard pad bytes.
    const terminator = Math.min(4, capacityBits - bits.length);
    pushBits(0, terminator);

    while (bits.length % 8 !== 0) {
        bits.push(false);
    }

    const padBytes = [0xEC, 0x11];
    let padIndex = 0;

    while (bits.length < capacityBits) {
        pushBits(padBytes[padIndex % 2], 8);
        padIndex += 1;
    }

    const data = [];

    for (let i = 0; i < bits.length; i += 8) {
        let byte = 0;

        for (let j = 0; j < 8; j += 1) {
            byte = (byte << 1) | (bits[i + j] ? 1 : 0);
        }

        data.push(byte);
    }

    // ── error correction, then interleaving ──
    const blocks = NUM_ECC_BLOCKS[eclIndex][version];
    const eccPerBlock = ECC_CODEWORDS_PER_BLOCK[eclIndex][version];
    const rawCodewords = Math.floor(rawDataModules(version) / 8);
    const shortBlockLength = Math.floor(rawCodewords / blocks);
    const numShortBlocks = blocks - (rawCodewords % blocks);
    const generator = rsGenerator(eccPerBlock);

    const dataBlocks = [];
    const eccBlocks = [];
    let offset = 0;

    for (let i = 0; i < blocks; i += 1) {
        const length = shortBlockLength - eccPerBlock + (i < numShortBlocks ? 0 : 1);
        const block = data.slice(offset, offset + length);
        offset += length;

        dataBlocks.push(block);
        eccBlocks.push(rsRemainder(block, generator));
    }

    const interleaved = [];

    for (let i = 0; i < shortBlockLength - eccPerBlock + 1; i += 1) {
        for (let b = 0; b < blocks; b += 1) {
            if (i < dataBlocks[b].length) {
                interleaved.push(dataBlocks[b][i]);
            }
        }
    }

    for (let i = 0; i < eccPerBlock; i += 1) {
        for (let b = 0; b < blocks; b += 1) {
            interleaved.push(eccBlocks[b][i]);
        }
    }

    // ── draw, mask, choose ──
    const symbol = new Matrix(version, ecl);
    symbol.drawFunctionPatterns();
    symbol.drawCodewords(interleaved);

    let chosenMask = options.mask;

    if (chosenMask === undefined || chosenMask === null) {
        let best = Infinity;

        for (let mask = 0; mask < 8; mask += 1) {
            symbol.applyMask(mask, true);
            symbol.drawFormatBits(ecl, mask);
            const score = penaltyScore(symbol.modules);
            symbol.applyMask(mask, true); // undo

            if (score < best) {
                best = score;
                chosenMask = mask;
            }
        }
    }

    if (! Number.isInteger(chosenMask) || chosenMask < 0 || chosenMask > 7) {
        throw new RangeError(`invalid mask ${chosenMask}`);
    }

    symbol.applyMask(chosenMask, true);
    symbol.drawFormatBits(ecl, chosenMask);

    return {
        size: symbol.size,
        version,
        ecl,
        mask: chosenMask,
        modules: symbol.modules,
    };
}

/**
 * Draw a symbol onto a canvas, scaled to fit.
 *
 * The canvas is sized from the caller's intent, not from the payload, and the
 * quiet zone is included because a QR without one does not scan reliably.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {{modules: boolean[][], size: number}} symbol
 * @param {{pixelSize?: number, quietZone?: number, dark?: string, light?: string}} [options]
 */
export function drawQrToCanvas(canvas, symbol, options = {}) {
    const pixelSize = Math.max(64, Math.floor(Number(options.pixelSize) || 232));
    const quietZone = Math.max(1, Math.floor(Number(options.quietZone) || 4));
    const dark = options.dark || '#0f172a';
    const light = options.light || '#ffffff';

    const totalModules = symbol.size + quietZone * 2;
    const scale = Math.max(1, Math.floor(pixelSize / totalModules));
    const dimension = totalModules * scale;

    canvas.width = dimension;
    canvas.height = dimension;

    const context = canvas.getContext('2d');

    if (! context) {
        throw new Error('canvas 2d context unavailable');
    }

    context.fillStyle = light;
    context.fillRect(0, 0, dimension, dimension);
    context.fillStyle = dark;

    for (let y = 0; y < symbol.size; y += 1) {
        for (let x = 0; x < symbol.size; x += 1) {
            if (symbol.modules[y][x]) {
                context.fillRect((x + quietZone) * scale, (y + quietZone) * scale, scale, scale);
            }
        }
    }

    return canvas;
}

export default { encodeQr, drawQrToCanvas };
