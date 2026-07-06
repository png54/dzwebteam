# CHANGELOG — DZ Web Team Production Build

## 🔴 Security (Critical)
- **Removed hardcoded Gemini API key** from `api.php` — replaced with safe placeholder; original key was exposed in plaintext
- **API key patched out of compiled JS bundle** (`assets/index-Clfv9eYh.js`) — key was also embedded as a client-side fallback, making it trivially extractable via browser DevTools; replaced with empty string so the PHP proxy remains the sole auth path
- **Rate limiting added** to `api.php`: 20 requests/min per IP, file-based (works on all shared hosts)
- Switched Gemini call from **`file_get_contents` → cURL** with full SSL verification, timeouts, and redirect blocking
- System instruction moved to Gemini's **`system_instruction` field** (eliminates prompt-injection risk)
- `.htaccess` blocks direct browser GET access to `api.php`; hides sensitive files (`.env`, `.log`, `.sql`, etc.)
- **Security headers** added everywhere: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`

## 🟡 API & Backend
- All error paths return correct **HTTP status codes** (400, 404, 405, 413, 429, 502)
- **Input validation**: max 2 000 UTF-8 chars, max 16 KB body, API key format guard
- `OPTIONS` pre-flight returns `204 No Content` with proper `Access-Control-Max-Age`
- `JSON_THROW_ON_ERROR` guards payload encoding

## 🟢 HTML / SEO / Accessibility
- Fixed `<title>` from *"My Google AI Studio App"* → brand-correct Arabic title with keywords
- Added `lang="ar"` + `dir="rtl"` to `<html>` (critical for RTL rendering, a11y, and Google)
- Full **Open Graph** + **Twitter Card** meta, canonical link, `robots` meta, `description`, `keywords`
- `<noscript>` styled Arabic fallback with contact link
- Inline critical CSS eliminates flash of white background on slow connections
- Stylesheet link moved before `<script>` to eliminate render-blocking race

## ⚡ Performance
- Gzip/Deflate compression for all text assets in `.htaccess`
- **Immutable long-term caching** for hashed CSS/JS (1 year); fonts 1 year; images 6 months; HTML 1 hour
- `preconnect` to Google Fonts CDN + `dns-prefetch` for Gemini API

## 🌐 Hosting & Ops
- `.htaccess` **SPA rewrite rule** — React Router deep-links no longer 404 on Apache
- Added `robots.txt`, `sitemap.xml`, `site.webmanifest` (PWA), `favicon.svg`, `404.html`
- `Options -Indexes` prevents directory listing
