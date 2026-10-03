# Page Verification Audit Matrix

**Repository:** `thai-lottery-ainul-islam`
**Generated:** 2026-10-01
**Scope:** every page surface enumerated by the static-contract suites
(`tests/Feature/Pages77To100StaticContractTest.php`,
`tests/Feature/Pages150To250StaticContractTest.php`,
`tests/Feature/Pages251To350StaticContractTest.php`).

---

## What this document is, and what it is not

This is a **verification ledger**, not a certificate. Each row records what has
actually been established about one page surface and, where nothing has been
established, says so in those words.

Every row below reads `NOT VERIFIED — RUNTIME UNAVAILABLE`. That is the honest
state, and it is deliberate:

- **No browser verification has been performed.** Nothing in this environment
  renders a page in a real browser, so no claim about visual correctness,
  client-side behaviour, or interactive state can be supported.
- **No hosted CI run has ever succeeded.** The repository has 11 workflow runs
  and every one of them failed, so no page has a green pipeline behind it.
- **Static analysis is not runtime verification.** The contract suites prove
  that a controller references a service, or that a route is declared. They
  cannot prove the page renders, responds, or behaves correctly for a visitor.

Writing `VERIFIED` in any row here without a runtime observation to support it
would make this document worse than useless: it would be a false assurance
attached to a real-money lottery platform. The honest status is recorded
instead, and it is recorded uniformly.

## Status vocabulary

| Status | Meaning |
|---|---|
| `NOT VERIFIED — RUNTIME UNAVAILABLE` | No runtime observation exists for this page. Static contracts may pass; that is not the same claim. |
| `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` | Verification cannot even be attempted until a missing runtime component is provisioned. |
| `NOT_CONFIGURED` | The surface exists in code but its configuration is absent, so there is nothing meaningful to verify yet. |

## Blocking components

Verification of every row is held behind the following, each of which is
currently **`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`**:

| Component | State | Consequence |
|---|---|---|
| Headless browser | absent | No page can be rendered or interacted with. |
| Hosted CI pipeline | 0 green runs in 11 | No independent reproduction of any local result. |
| Result importer schedule | not scheduled on any lane | Result pages have no live data path; their runtime content is `NOT_CONFIGURED`. |
| Rust integrity verifier runtime | Cargo toolchain not provisioned here | The integrity gate cannot be executed; see `RUST-RUNTIME-REPORT.md`. |

---

## Page matrix

| Page | Surface | Static contract | Runtime status |
|---|---|---|---|
| 1 | page-001 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 2 | page-002 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 3 | page-003 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 4 | page-004 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 5 | page-005 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 6 | page-006 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 7 | page-007 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 8 | page-008 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 9 | page-009 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 10 | page-010 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 11 | page-011 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 12 | page-012 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 13 | page-013 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 14 | page-014 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 15 | page-015 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 16 | page-016 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 17 | page-017 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 18 | page-018 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 19 | page-019 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 20 | page-020 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 21 | page-021 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 22 | page-022 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 23 | page-023 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 24 | page-024 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 25 | page-025 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 26 | page-026 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 27 | page-027 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 28 | page-028 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 29 | page-029 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 30 | page-030 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 31 | page-031 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 32 | page-032 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 33 | page-033 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 34 | page-034 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 35 | page-035 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 36 | page-036 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 37 | page-037 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 38 | page-038 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 39 | page-039 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 40 | page-040 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 41 | page-041 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 42 | page-042 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 43 | page-043 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 44 | page-044 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 45 | page-045 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 46 | page-046 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 47 | page-047 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 48 | page-048 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 49 | page-049 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 50 | page-050 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 51 | page-051 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 52 | page-052 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 53 | page-053 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 54 | page-054 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 55 | page-055 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 56 | page-056 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 57 | page-057 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 58 | page-058 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 59 | page-059 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 60 | page-060 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 61 | page-061 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 62 | page-062 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 63 | page-063 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 64 | page-064 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 65 | page-065 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 66 | page-066 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 67 | page-067 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 68 | page-068 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 69 | page-069 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 70 | page-070 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 71 | page-071 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 72 | page-072 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 73 | page-073 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 74 | page-074 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 75 | page-075 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 76 | page-076 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 77 | page-077 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 78 | page-078 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 79 | page-079 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 80 | page-080 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 81 | page-081 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 82 | page-082 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 83 | page-083 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 84 | page-084 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 85 | page-085 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 86 | page-086 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 87 | page-087 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 88 | page-088 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 89 | page-089 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 90 | page-090 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 91 | page-091 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 92 | page-092 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 93 | page-093 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 94 | page-094 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 95 | page-095 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 96 | page-096 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 97 | page-097 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 98 | page-098 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 99 | page-099 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 100 | page-100 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 101 | page-101 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 102 | page-102 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 103 | page-103 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 104 | page-104 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 105 | page-105 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 106 | page-106 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 107 | page-107 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 108 | page-108 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 109 | page-109 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 110 | page-110 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 111 | page-111 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 112 | page-112 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 113 | page-113 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 114 | page-114 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 115 | page-115 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 116 | page-116 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 117 | page-117 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 118 | page-118 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 119 | page-119 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 120 | page-120 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 121 | page-121 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 122 | page-122 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 123 | page-123 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 124 | page-124 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 125 | page-125 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 126 | page-126 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 127 | page-127 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 128 | page-128 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 129 | page-129 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 130 | page-130 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 131 | page-131 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 132 | page-132 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 133 | page-133 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 134 | page-134 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 135 | page-135 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 136 | page-136 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 137 | page-137 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 138 | page-138 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 139 | page-139 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 140 | page-140 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 141 | page-141 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 142 | page-142 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 143 | page-143 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 144 | page-144 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 145 | page-145 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 146 | page-146 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 147 | page-147 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 148 | page-148 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 149 | page-149 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 150 | page-150 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 151 | page-151 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 152 | page-152 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 153 | page-153 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 154 | page-154 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 155 | page-155 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 156 | page-156 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 157 | page-157 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 158 | page-158 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 159 | page-159 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 160 | page-160 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 161 | page-161 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 162 | page-162 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 163 | page-163 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 164 | page-164 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 165 | page-165 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 166 | page-166 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 167 | page-167 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 168 | page-168 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 169 | page-169 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 170 | page-170 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 171 | page-171 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 172 | page-172 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 173 | page-173 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 174 | page-174 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 175 | page-175 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 176 | page-176 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 177 | page-177 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 178 | page-178 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 179 | page-179 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 180 | page-180 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 181 | page-181 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 182 | page-182 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 183 | page-183 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 184 | page-184 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 185 | page-185 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 186 | page-186 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 187 | page-187 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 188 | page-188 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 189 | page-189 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 190 | page-190 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 191 | page-191 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 192 | page-192 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 193 | page-193 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 194 | page-194 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 195 | page-195 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 196 | page-196 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 197 | page-197 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 198 | page-198 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 199 | page-199 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 200 | page-200 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 201 | page-201 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 202 | page-202 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 203 | page-203 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 204 | page-204 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 205 | page-205 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 206 | page-206 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 207 | page-207 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 208 | page-208 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 209 | page-209 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 210 | page-210 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 211 | page-211 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 212 | page-212 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 213 | page-213 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 214 | page-214 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 215 | page-215 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 216 | page-216 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 217 | page-217 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 218 | page-218 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 219 | page-219 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 220 | page-220 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 221 | page-221 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 222 | page-222 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 223 | page-223 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 224 | page-224 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 225 | page-225 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 226 | page-226 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 227 | page-227 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 228 | page-228 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 229 | page-229 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 230 | page-230 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 231 | page-231 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 232 | page-232 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 233 | page-233 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 234 | page-234 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 235 | page-235 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 236 | page-236 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 237 | page-237 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 238 | page-238 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 239 | page-239 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 240 | page-240 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 241 | page-241 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 242 | page-242 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 243 | page-243 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 244 | page-244 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 245 | page-245 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 246 | page-246 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 247 | page-247 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 248 | page-248 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 249 | page-249 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 250 | page-250 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 251 | page-251 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 252 | page-252 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 253 | page-253 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 254 | page-254 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 255 | page-255 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 256 | page-256 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 257 | page-257 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 258 | page-258 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 259 | page-259 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 260 | page-260 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 261 | page-261 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 262 | page-262 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 263 | page-263 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 264 | page-264 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 265 | page-265 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 266 | page-266 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 267 | page-267 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 268 | page-268 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 269 | page-269 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 270 | page-270 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 271 | page-271 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 272 | page-272 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 273 | page-273 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 274 | page-274 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 275 | page-275 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 276 | page-276 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 277 | page-277 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 278 | page-278 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 279 | page-279 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 280 | page-280 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 281 | page-281 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 282 | page-282 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 283 | page-283 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 284 | page-284 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 285 | page-285 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 286 | page-286 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 287 | page-287 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 288 | page-288 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 289 | page-289 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 290 | page-290 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 291 | page-291 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 292 | page-292 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 293 | page-293 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 294 | page-294 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 295 | page-295 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 296 | page-296 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 297 | page-297 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 298 | page-298 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 299 | page-299 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 300 | page-300 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 301 | page-301 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 302 | page-302 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 303 | page-303 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 304 | page-304 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 305 | page-305 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 306 | page-306 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 307 | page-307 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 308 | page-308 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 309 | page-309 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 310 | page-310 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 311 | page-311 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 312 | page-312 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 313 | page-313 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 314 | page-314 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 315 | page-315 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 316 | page-316 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 317 | page-317 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 318 | page-318 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 319 | page-319 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 320 | page-320 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 321 | page-321 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 322 | page-322 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 323 | page-323 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 324 | page-324 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 325 | page-325 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 326 | page-326 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 327 | page-327 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 328 | page-328 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 329 | page-329 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 330 | page-330 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 331 | page-331 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 332 | page-332 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 333 | page-333 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 334 | page-334 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 335 | page-335 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 336 | page-336 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 337 | page-337 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 338 | page-338 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 339 | page-339 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 340 | page-340 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 341 | page-341 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 342 | page-342 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 343 | page-343 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 344 | page-344 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 345 | page-345 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 346 | page-346 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 347 | page-347 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 348 | page-348 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 349 | page-349 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 350 | page-350 | enumerated by contract suite | NOT VERIFIED — RUNTIME UNAVAILABLE |
---

## How a row becomes verified

A row may only be changed from `NOT VERIFIED — RUNTIME UNAVAILABLE` when all of
the following are true and the evidence is attached:

1. The page was rendered in a real browser against a running application, and
   the response status, rendered content and console state were recorded.
2. The same page passed in a **hosted** CI run, not only locally.
3. Any data the page displays came from its real source, not a fixture lane.

Until then the status stands. A row is never upgraded on the strength of a
passing static contract alone.
