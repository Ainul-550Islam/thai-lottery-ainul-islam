# PROMPT 6 — WORLD-CLASS ULTRA PROFESSIONAL FRONTEND
## THAILOTTO ENTERPRISE WAGERING PLATFORM
## 3D / GLASSMORPHISM / PREMIUM FINTECH / LOTTERY UX / RESPONSIVE / ACCESSIBLE

You are the NEXT implementation agent.

PROMPT 1–5 handled backend, GLO, wallet, payment, security, results,
jobs, APIs, compliance and production cutover.

THIS PROMPT focuses on:

- ultra-professional frontend
- premium 3D visual language
- glassmorphism used carefully
- fintech-grade information hierarchy
- high-end lottery/product presentation
- responsive desktop/tablet/mobile
- EN/TH
- dark/light support if already present
- animation without harming performance
- accessibility
- backend/API contract preservation

IMPORTANT:

DO NOT rebuild backend logic unnecessarily.

If a design requirement exposes an actual backend/data gap,
make the minimum correct backend change and test it.

============================================================
# 0. ABSOLUTE NO-SKIP RULE
============================================================

1. DO NOT SKIP ANY FILE.
2. DO NOT SKIP ANY EXISTING COMPONENT.
3. DO NOT SKIP ANY responsive state.
4. DO NOT SKIP mobile.
5. DO NOT SKIP tablet.
6. DO NOT SKIP desktop.
7. DO NOT SKIP EN.
8. DO NOT SKIP TH.
9. DO NOT SKIP accessibility.
10. DO NOT SKIP animation reduced-motion behavior.
11. DO NOT SKIP backend contract compatibility.
12. DO NOT SKIP tests.
13. DO NOT hide failed/skipped tests.
14. A skipped test is NOT a pass.
15. Do NOT remove existing functional logic to achieve a visual result.

============================================================
# 1. FULL FILE CONTENT RULE
============================================================

For EVERY modified file:

- Read the entire current file.
- Preserve existing logic.
- Provide COMPLETE final file content.
- No snippets.
- No omitted sections.
- No placeholders.

FORBIDDEN:

# ... existing code ...
// ... existing code ...
/* existing code */
...
same as above
rest unchanged
omitted

FULL FILE CONTENT ONLY.

============================================================
# 2. DESIGN PRINCIPLES
============================================================

Design direction:

PREMIUM
MODERN
TRUSTWORTHY
FINTECH-GRADE
LOTTERY-CENTRIC
CLEAN
FAST
ACCESSIBLE
HIGH-CONVERSION

Visual language:

- layered glass panels
- subtle depth
- restrained gradients
- soft borders
- elevated metric cards
- 3D lottery-number presentation
- realistic hierarchy
- premium spacing
- strong typography
- meaningful micro-interactions
- no excessive decoration

DO NOT make the site look like:

- casino spam
- gambling banner farm
- crypto scam landing page
- neon gaming template
- government website clone
- fake financial dashboard

No fake badges.
No fake “verified” claims.
No fake counters.
No fake live status.

============================================================
# 3. DESIGN SYSTEM
============================================================

1. resources/css/app.css
# DESIGN SYSTEM AGENT:
# Build the canonical global design system.
# Define typography hierarchy, spacing, radii, shadows, glass surfaces,
# focus states, buttons, forms, cards, tables, alerts, badges and responsive
# breakpoints.
# Use CSS variables/design tokens.
# Do not scatter arbitrary values across individual components.
# Provide light/dark variables if existing theme supports both.
# Include prefers-reduced-motion handling.
# Ensure contrast remains accessible.
# Preserve Vite compatibility.

2. resources/css/theme.css
# THEME AGENT:
# Centralize brand theme variables:
# background layers
# glass layers
# border opacity
# typography
# accent
# success
# warning
# danger
# information
# lottery-specific highlights
# Do NOT hardcode government/GLO identity colors or imagery as proof of affiliation.
# Keep theme configurable.

3. resources/css/components/glass.css
# GLASS UI AGENT:
# Create reusable glass surfaces.
# Use backdrop-filter only when supported.
# Provide graceful non-glass fallback.
# Prevent excessive transparency behind text.
# Ensure readable text and focus outlines.
# Do not turn every element into glass; preserve hierarchy.

4. resources/css/components/metrics.css
# METRIC UI AGENT:
# Build premium metric cards with:
# large value
# label
# provenance/status
# optional trend
# icon/visual
# responsive stacking
# No fake trend arrows or percentages.
# Status wording must match backend state.

5. resources/css/components/lottery-3d.css
# LOTTERY VISUAL AGENT:
# Build premium 3D number/ticket visuals using CSS transforms,
# perspective, shadows and layered surfaces.
# Keep animation subtle.
# Respect prefers-reduced-motion.
# Never rely on 3D animation to communicate critical result information.

------------------------------------------------------------
# 4. SHARED LAYOUT / NAVIGATION
------------------------------------------------------------

6. resources/views/layouts/app.blade.php
# LAYOUT AGENT:
# Rebuild the complete shell around the new design system.
# Preserve all existing backend-driven navigation and authorization.
# Upgrade header, navigation, mobile menu, breadcrumbs, alerts, footer,
# language selector and global loading/error states.
# Never expose internal/admin/payment-only links to unauthorized users.
# Keep legal identity/provenance accurate.
# No fake health/version claims.
# Verify EN/TH and mobile navigation.

7. resources/views/components/public-page/footer.blade.php
# FOOTER DESIGN/LEGAL AGENT:
# Premium glass footer.
# Preserve legal configuration as source of truth.
# Include approved operator/support/legal links only.
# No fabricated address/phone/email.
# Ensure mobile stacking and accessible focus states.
# EN/TH parity.

8. resources/views/components/navigation/main-nav.blade.php
# NAVIGATION AGENT:
# If current navigation is not centralized, create/upgrade this component.
# Use the canonical route inventory.
# Support public/auth states.
# Mobile menu must be keyboard accessible.
# No duplicate route definitions.
# Add active-page state and accessible ARIA state.

9. resources/views/components/navigation/mobile-nav.blade.php
# MOBILE NAV AGENT:
# Build complete mobile navigation.
# Prevent body-scroll bugs.
# Escape-to-close.
# Focus management.
# Touch target >= accessible size.
# Locale switching works.
# Authenticated financial routes remain protected.

10. resources/views/components/ui/button.blade.php
# UI COMPONENT AGENT:
# Create a reusable button component with variants:
# primary
# secondary
# ghost
# success
# danger
# loading
# disabled
# icon-only
# Ensure accessible names and keyboard states.
# Preserve existing route/form behavior.

------------------------------------------------------------
# 5. PUBLIC HOME
------------------------------------------------------------

11. resources/views/home.blade.php
# HOME REDESIGN AGENT:
# Transform the homepage into the premium product experience.
# Sections:
# hero
# next draw
# lottery products
# result highlights
# metrics
# fees/payment summary
# support
# app links
# responsible-gaming notice
# CTA/footer
# Keep every value backend-sourced.
# No fake numbers.
# No fake urgency.
# No fake “official” claims.
# Add elegant 3D lottery visual treatment without misleading users.

12. resources/views/components/home/hero.blade.php
# HERO AGENT:
# Build cinematic but trustworthy hero.
# Include real next-draw data only when available.
# Use layered glass surfaces and restrained 3D depth.
# Include primary/secondary CTAs.
# EN/TH.
# Mobile layout must be independently designed, not merely scaled down.

13. resources/views/components/home/next-draw.blade.php
# NEXT-DRAW AGENT:
# Premium draw countdown/card.
# Time must come from server-authoritative data.
# Avoid client-only fake countdown assumptions.
# Handle unavailable/stale draw state honestly.
# Add reduced-motion behavior.
# Add timezone-safe display.

14. resources/views/components/home/lottery-grid.blade.php
# PRODUCT GRID AGENT:
# Build responsive lottery card grid.
# Use canonical product data.
# Include lane status, draw date, result state and CTA where available.
# No fake jackpot/prize values.
# 3D visual elevation without harming readability.

15. resources/views/components/home/stats.blade.php
# METRICS AGENT:
# Premium glass metric cards.
# Every metric must show precise semantic status.
# Do not label database-derived numbers “official” or externally verified unless
# the backend explicitly proves it.
# Empty state must be honest.
# Responsive layout.

------------------------------------------------------------
# 6. RESULTS / ARCHIVE
------------------------------------------------------------

16. resources/views/results/index.blade.php
# RESULTS UI AGENT:
# Redesign result presentation into a premium data experience.
# Keep Blade presentation-only.
# No DB queries in Blade.
# No raw internal API URLs.
# No sample/demo ticket numbers.
# Use structured result data from controller/service.
# Show provenance/state visibly.
# Archive/year navigation must be clear and responsive.
# Result numbers should use high-legibility 3D cards.

17. resources/views/results/search.blade.php
# SEARCH UI AGENT:
# Build premium result search UI.
# Input, validation state, recent query state if supported,
# result/no-result/error states.
# Accessible search form.
# No client-side trust of result data.
# EN/TH.

18. resources/views/components/results/result-card.blade.php
# RESULT CARD AGENT:
# Create reusable result card.
# Display lane, date, numbers, source/provenance state,
# publication status and canonical detail link.
# Unknown/unverified data must NEVER look official.
# Add responsive and print-friendly presentation.

------------------------------------------------------------
# 7. PLAYER EXPERIENCE
------------------------------------------------------------

19. resources/views/player/dashboard.blade.php
# PLAYER DASHBOARD AGENT:
# Premium member dashboard.
# Glass metric panels for wallet, bets, draws and notifications.
# Values must be backend sourced.
# No fake identity or fake balance.
# Provide loading/error/empty states.
# Accessibility and responsive layout.
# Keep financial actions prominent but not misleading.

20. resources/views/player/wallet.blade.php
# WALLET UI AGENT:
# Build fintech-grade wallet interface.
# Clearly distinguish:
# available
# locked
# reserved
# pending
# settled
# currency
# ledger activity
# Never combine currencies.
# Exact amount formatting based on backend currency rules.
# Add transaction timeline/table with responsive mobile mode.

21. resources/views/player/deposit.blade.php
# DEPOSIT UI AGENT:
# Redesign deposit flow.
# Provider cards must show only backend-capable methods.
# Clearly distinguish hosted checkout/manual instructions/pending/refused.
# Never display payment success before server confirmation.
# Preserve CSRF/idempotency behavior.
# Add step indicator without implying completion.

22. resources/views/player/withdraw.blade.php
# WITHDRAWAL UI AGENT:
# Premium payout form.
# Canonical bank/mobile/crypto destination fields.
# Clearly show:
# available balance
# requested amount
# fee if applicable
# final amount if known
# review state
# no fake instant-payout promise.
# Keep backend validation authoritative.

23. resources/views/player/bets.blade.php
# BET HISTORY AGENT:
# Build high-quality bet-history interface.
# Status chips:
# pending
# open
# settled
# won
# lost
# cancelled
# Use semantic colors with accessible contrast.
# Mobile card/table transformation.
# No client-generated status.

24. resources/views/player/profile.blade.php
# PROFILE AGENT:
# Premium profile/KYC/responsible-gaming layout.
# Separate:
# personal identity
# verification
# security
# responsible gaming
# payment destinations
# Use real status values.
# No fake examples/default email/user ID.

25. resources/views/player/draw-detail.blade.php
# DRAW DETAIL AGENT:
# Build rich detail page.
# Show draw information, result state, provenance,
# ticket/bet availability if supported, archive relation.
# 3D visual result presentation.
# No unpublished/future data leakage.

------------------------------------------------------------
# 8. BET SLIP / INTERACTION
------------------------------------------------------------

26. resources/js/svgbet-slip.js
# FRONTEND INTERACTION AGENT:
# Keep existing server contract.
# Improve UX:
# animated selection
# subtotal
# validation feedback
# submit lock
# idempotency key
# server-confirmed state
# error recovery
# reduced-motion support
# Never calculate authoritative price/balance client-side.
# Client calculations are display-only.

27. resources/js/svgdeposit.js
# DEPOSIT INTERACTION AGENT:
# Upgrade interaction polish.
# Loading state
# provider selection
# redirect state
# manual instructions
# failure
# retry
# no duplicate submission.
# Preserve backend idempotency.

28. resources/js/svgwithdraw.js
# WITHDRAW INTERACTION AGENT:
# Upgrade withdrawal UX.
# Amount validation display
# destination validation hints
# confirmation step
# locked submit
# server state refresh
# pending/rejected/completed display.
# Never infer final payout state from UI alone.

------------------------------------------------------------
# 9. RESPONSIVE / ACCESSIBILITY TESTING
------------------------------------------------------------

29. tests/Feature/UI/PremiumExperienceTest.php
# NEW TEST FILE:
# Test public/player pages for:
# EN
# TH
# desktop layout assumptions
# mobile-safe output
# canonical links
# no raw translation keys
# no fake identity
# no fake payment success
# no fake result
# no internal API leakage
# expected CTA availability
# accessibility labels
# authenticated route protection.
# This is a real render/integration test, not a string-only test.

30. tests/Feature/UI/DesignSystemRegressionTest.php
# NEW TEST FILE:
# Verify shared design-system components exist and render correctly.
# Verify:
# button variants
# metric cards
# glass surfaces
# result cards
# navigation
# mobile menu
# EN/TH locale rendering
# reduced-motion CSS support
# no unsafe inline scripts
# no deprecated duplicate UI component
# no placeholder design text.
# Also verify existing backend behavior remains intact after UI changes.

============================================================
# 10. DESIGN QUALITY GATE
============================================================

The final UI MUST satisfy:

[ ] premium visual hierarchy
[ ] consistent spacing
[ ] consistent typography
[ ] responsive desktop
[ ] responsive tablet
[ ] responsive mobile
[ ] EN
[ ] TH
[ ] keyboard navigation
[ ] focus visibility
[ ] screen-reader semantics
[ ] reduced motion
[ ] glass fallback
[ ] fast initial render
[ ] no layout-breaking 3D effects
[ ] no fake status
[ ] no fake numbers
[ ] no fake marketing
[ ] no fake official claim
[ ] no fake financial state

============================================================
# 11. PERFORMANCE GATE
============================================================

Do NOT add heavy frontend effects without measuring impact.

Verify:

[ ] CSS bundle reasonable
[ ] JS bundle reasonable
[ ] images optimized
[ ] lazy loading where appropriate
[ ] no unnecessary polling
[ ] no repeated API fetch
[ ] no duplicate service request
[ ] no animation loop that consumes excessive CPU
[ ] no huge 3D asset
[ ] mobile performance acceptable

============================================================
# 12. BACKEND CONTRACT RULE
============================================================

DO NOT change backend semantics merely to make UI easier.

The frontend must consume canonical:

- wallet
- payment
- result
- bet
- RG
- KYC
- notification
- support
- GLO provenance

services.

If a backend change is necessary:

[ ] modify canonical service
[ ] preserve API compatibility
[ ] update tests
[ ] document migration
[ ] provide COMPLETE changed file content

============================================================
# 13. FULL VALIDATION
============================================================

Run where available:

php artisan test
npm run build
php -l <changed PHP files>
node --check <changed JS files>

Also verify:

[ ] all public pages
[ ] all player pages
[ ] all auth pages
[ ] results/archive
[ ] deposit
[ ] withdrawal
[ ] bet slip
[ ] wallet
[ ] EN/TH
[ ] mobile
[ ] desktop

If any test cannot execute:

NOT VERIFIED

Never call it PASS.

============================================================
# 14. FULL-FILE OUTPUT
============================================================

Return:

1. COMPLETE content of every modified file.
2. COMPLETE content of every NEW file.
3. Complete CSS.
4. Complete Blade.
5. Complete JS.
6. Complete PHP if backend changed.
7. Complete tests.
8. Exact commands run.
9. PASS count.
10. FAIL count.
11. SKIPPED count.
12. NOT VERIFIED count.
13. Remaining OPEN findings.
14. Any backend semantic changes.
15. Any design-performance tradeoff.

FORBIDDEN:

...
omitted
same as existing
existing logic unchanged
# existing code
partial file
placeholder

============================================================
# 15. FINAL ACCEPTANCE
============================================================

DO NOT DECLARE PROMPT 6 COMPLETE UNLESS:

[ ] all 30 targets inspected
[ ] all 30 targets implemented/verified
[ ] existing backend behavior preserved
[ ] no placeholder code
[ ] no placeholder design text
[ ] no fake data
[ ] no fake payment
[ ] no fake result
[ ] no fake GLO status
[ ] no fake health status
[ ] no raw API leakage
[ ] no untranslated UI
[ ] EN verified
[ ] TH verified
[ ] mobile verified
[ ] desktop verified
[ ] accessibility verified
[ ] reduced-motion verified
[ ] performance checked
[ ] tests executed
[ ] skipped tests explicitly reported
[ ] unverified tests explicitly reported
[ ] complete changed-file content supplied

============================================================
# FINAL INSTRUCTION
============================================================

START FROM THE CURRENT CODE.

DO NOT TRUST PREVIOUS FRONTEND CLAIMS.

DO NOT REWRITE WORKING BACKEND LOGIC JUST FOR DESIGN.

DO NOT SKIP ANY CODE.

DO NOT SKIP ANY TEST.

DO NOT HIDE SKIPPED TESTS.

DO NOT USE PLACEHOLDER COMMENTS.

DO NOT USE:
# ... existing code ...

MAKE THE FRONTEND LOOK ENTERPRISE-GRADE, PREMIUM, MODERN AND TRUSTWORTHY.

USE 3D AND GLASS EFFECTS AS A DESIGN SYSTEM,
NOT AS DECORATION EVERYWHERE.

PRESERVE ALL MONEY / WALLET / PAYMENT / GLO / SECURITY RULES.

TRACE EVERY UI ACTION TO THE REAL BACKEND CONTRACT.

PROVIDE COMPLETE FINAL FILE CONTENT.

REPORT ALL VERIFIED AND NOT VERIFIED RESULTS HONESTLY.