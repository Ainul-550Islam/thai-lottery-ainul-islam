/**
 * TYPE: page entry
 * PURPOSE: Download destination navigation and browser print enhancement.
 *
 * Also loads download-app-portal.js, which surfaces the VERIFIED Android
 * release (real manifest data only) in the page's integrity slot. It renders
 * nothing invented: without a verified manifest the slot shows the honest
 * NOT_CONFIGURED state.
 */
import './public-next-pages.js';
import '../download-app-portal.js';
