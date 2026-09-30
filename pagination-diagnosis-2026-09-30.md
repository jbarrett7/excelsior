# Archive pagination: diagnosis, 30 Sept 2026

## Status

Static diagnosis from the WPCode export. **Not yet proven live.** Every request from
the cloud environment this was done in was answered by Cloudflare with
`403` and `cf-mitigated: challenge` (curl and headless Chromium alike), so the curl
tests below have to be run from your own connection.

Cloudflare sits in front of the site (`server: cloudflare`). It isn't in the
caching notes, so it has to be purged as part of every test.

## Update, 30 Sept 09:01: Cloudflare challenges every request

All four curl tests were run from a UK home connection (cf-ray ...-LHR). Every one of
them, including the POST, returned `403` with `cf-mitigated: challenge`. None of them
reached WordPress, NitroPack or mod_pagespeed. From the cloud environment,
`/robots.txt` and `/wp-admin/admin-ajax.php` were challenged as well, so it looks
like a site-wide rule (Under Attack mode, or a catch-all custom rule or Bot Fight
setting) rather than a rule on particular paths.

Consequences:
- Curl can't test this site while that rule is in place, so the tests move to the
  browser console. See "Console tests" below.
- It's a second possible mechanism. If Cloudflare also challenges the JetSmartFilters
  XHR, pagination fails in exactly the same silent way. Console test B tells the two
  apart: a 403 with `cf-mitigated` points at Cloudflare, a redirect that strips
  `jsf_ajax` points at the snippet. Both can be true at once.
- Worth checking separately: NitroPack's optimisation crawler and search engines
  may be challenged too.

## Prime suspect: "EXC Legacy Query Params" (added 23 Sept 2026)

This is one of four snippets added on 23 Sept by Atherstone Digital. None of them
were on the list of recent changes. It runs on `template_redirect` at priority 1
and 301-redirects any GET to a `.html` URL that carries a listed "legacy" parameter.
`jsf_ajax` is on that list:

```
$legacy = array( 'dir', 'order', 'mode', 'limit', 'p', 'cat', 'price', 'ajaxcatalog', 'q', 'jsf_ajax' );
```

JetSmartFilters' "Self" AJAX request type sends its request to the current page
(Crocoblock KB). On this site that's `/dried-flowers.html?jsf_ajax=1&...`. If that
request is a GET, the snippet 301s it to the same URL with `jsf_ajax` removed. The
XHR follows the redirect silently and gets a full HTML page instead of the JSON
response JSF expects. JSF has already cancelled the link's default navigation, so
nothing happens and nothing visible errors. That matches the symptom exactly.

Signature: this is the only code that removes `jsf_ajax` but keeps `jsf_force_referrer`,
`jsf_referrer_sequence`, `orderby` and `paged`. A redirect with that Location header
is this snippet.

Unproven: whether the JSF request is a GET. If it's a POST, this snippet doesn't
fire (it's guarded to GET only) and the suspect is cleared.

## Tests (run from your machine, logged out, no cookies)

    B=https://excelsiorwholesale.co.uk; T=$(date +%s)

1. Cache layer: `curl -sI "$B/dried-flowers.html?cb=$T"` and `curl -sI "$B/dried-flowers.html?cb=$T&nocache=1"`.
   Report: `server`, `cf-cache-status`, `cf-mitigated`, any `x-nitro-*`, `x-page-speed` / `x-mod-pagespeed`, `cache-control`, `age`.
2. The decisive one: `curl -sI "$B/dried-flowers.html?jsf_ajax=1&jsf_force_referrer=self&jsf_referrer_sequence=late&orderby=date&paged=3&cb=$T"`.
   The snippet is guilty if this returns `301`, `x-redirect-by: WordPress`, and a `location` without `jsf_ajax` that still has the other parameters.
3. Control: the same URL with `-X POST --data 'exc=1'`. Expect no redirect.
4. Server-side pagination: compare `grep -o 'data-product_id="[0-9]*"' | head -8` for `?paged=2` against page 1.
   Also run `curl -sI` on `/dried-flowers.html/page/2/`. That form is expected to 404 and doesn't matter, because the links use `?paged=`.

If Cloudflare challenges your curl too, say so rather than working around it.

## Console tests (replace the curl tests while Cloudflare challenges curl)

Run them in the DevTools console on the live site, in a fresh incognito window,
logged out. These requests are same-origin, so every response header is readable.
The Network tab shows each redirect hop, with its Location and x-redirect-by. See
the chat reply for the exact code.

## DevTools checks (incognito, logged out)

Move the mouse or scroll once first: NitroPack delays JS until you interact, so a
check run too early reads "not initialised" falsely. See the chat reply for the
one-liners. The Network tab, filtered to `jsf` with Preserve log on, is the ground
truth for the request method, status and redirect.

## Fix (only if test 2 or the DevTools check confirms)

Replace the whole code of "EXC Legacy Query Params" with
`snippets/exc-legacy-query-params-v2.php`.
Settings: PHP Snippet, Auto Insert, Frontend Only, priority 10, unchanged from now.

Change: `jsf_ajax` comes off the legacy list, and any request carrying a `jsf` or
`jsf_*` parameter is never redirected. The rest of the snippet's behaviour is unchanged.

If Cloudflare blocks saving the snippet (the 23 Sept notes say it blocked edits to
two others), the equivalent settings change is to **deactivate "EXC Legacy
Query Params"**. That restores pagination but loses the Magento sort/filter 301s
until the v2 code can be saved.

## Bisect (only if test 2 comes back clean)

Toggle one at a time. After each change, invalidate NitroPack and purge Cloudflare,
test in a fresh incognito window, then re-enable before moving on.

1. EXC Legacy Query Params
2. EXC Redirect Status 301
3. Remember Page via Storage
4. Scroll to Top Pagination
5. EXC Pagination Canonical
6. Hide Filters when Empty, JS Price Slider, Pop Out Filters (one at a time)
7. EXC Products Carousel Shortcode (only if the carousel is on archive templates)

Do not deactivate the .html URL Handler: every category URL depends on it.
