🚀 Just built a WooCommerce Social Proof Notification Plugin — from scratch, in pure PHP + Vanilla JS.

No third-party libraries. No SaaS subscription. Zero performance impact.

Here's what it does 👇

---

🛒 WHAT IT SHOWS

Real-time purchase popups that display:
✅ A buyer's name (randomly selected, per-language)
✅ The actual product name from your WooCommerce store
✅ The real product price
✅ A natural timestamp ("Just now", "2 mins ago")
✅ A product thumbnail
✅ One-click redirect to the product detail page

Example:
💬 "Lukas hat gerade gekauft — iPhone 15 Case · €29,99 · Gerade eben"

---

📱 RESPONSIVE BY DESIGN

• Desktop → top-right corner, up to 3 stacked popups
• Mobile → top-left corner, 1 popup at a time (old one removes first, then new one slides in — no overlap)
• Works on every real iOS and Android device

---

⚡ PERFORMANCE FIRST

• Product data cached in WordPress transients (2 hours)
• No external API calls
• CSS transitions only — no animation libraries
• Tab Visibility API: pauses when the tab is hidden, saving CPU
• Z-index: 2147483647 — nothing covers it

---

🌍 7 LANGUAGES, AUTO-DETECTED

Detects the active language from Google Translate via:
1. googtrans cookie
2. html[lang] attribute
3. navigator.language fallback

Supports: 🇩🇪 German · 🇬🇧 English · 🇳🇱 Dutch · 🇫🇷 French · 🇪🇸 Spanish · 🇮🇹 Italian · 🇵🇱 Polish

Each language has its own curated pool of 40–50 region-specific names so the popups feel genuinely local.

---

🔧 BUILT LIKE A PROPER WORDPRESS PLUGIN

✅ register_activation_hook — validates WooCommerce is active, stores version, clears cache
✅ register_deactivation_hook — cleans up transients
✅ register_uninstall_hook — removes all database options on delete
✅ Upgrade routine via plugins_loaded — version-aware cache busting
✅ WooCommerce HPOS compatibility declaration
✅ Admin notice if WooCommerce is missing
✅ Full PHP docblock headers (Plugin Name, URI, Version, Requires, License)
✅ All output properly escaped (esc_html, esc_url_raw, wp_json_encode, wp_kses)

---

💡 THE TECH DETAIL I'M MOST PROUD OF

The old approach built message text in PHP and cached it.
Result: JS just looped the same 10 strings forever.

The fix: PHP caches only raw product data (name, price, URL, thumb).
JS picks a fresh random name + action each time from pools of 40–50 names.
22 unique messages shown before any repeat. Reshuffled, not looped.

---

🗂️ STACK
PHP 7.4+ · WordPress 5.8+ · WooCommerce 5.0+ · Vanilla JS (ES5, no build step)

---
