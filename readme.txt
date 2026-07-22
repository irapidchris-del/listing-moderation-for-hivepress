=== Automated Listing Moderation for HivePress ===
Contributors: chrisb
Tags: hivepress, moderation, spam, listings, marketplace
Requires at least: 5.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blocks or risk-scores HivePress listing submissions containing blocked words, contact details, duplicate content or AI-flagged text and photos.

== Description ==

Automated Listing Moderation adds a configurable moderation layer to the HivePress listing submit and edit forms. Everything is configured under **HivePress → Settings → Listings → Automated Moderation** (the OpenAI key lives on the **Integrations** tab).

**Two enforcement levels**

* **Block** — the form is refused with a specific inline error before anything is saved.
* **Risk score** — soft signals accumulate points; if the total reaches your threshold the listing is accepted but **held as Pending** for admin review, using HivePress's native moderation flow (native pending status, admin notification email, and admin-menu pending badge). When you approve a held listing, the native approval email still reaches the vendor.

There is deliberately **no auto-delete tier**: heuristics can false-positive, so a human always makes the final negative decision.

**Detectors**

* **Blocked keywords** — words or phrases, comma- or newline-separated, matched case-insensitively (including inside longer words).
* **Blocked patterns** — regular expressions, one per line, for whole-word matching and advanced rules.
* **Character-evasion catching** — optionally re-checks your lists against copies of the text with accents removed, invisible characters stripped, and common leet substitutions reversed (so blocking "shit" also catches "sh1t" and "$hit"). Legitimate accented text is never blocked by this option.
* **Phone numbers** — sequences of 9–14 digits with optional spacing, dashes, brackets, or a leading +. Prices, years, dates and IP addresses are not affected.
* **Email addresses** — anything shaped like an email address.
* **Website addresses** — http/https/www links plus bare domains with common extensions.
* **Duplicate titles and descriptions** — compares precomputed fingerprints (case, formatting and spacing insensitive) against live and pending listings; one small query per submission. Existing listings are fingerprinted automatically in the background.
* **AI text review (OpenAI)** — sends listing text to OpenAI's free Moderation endpoint (omni-moderation-latest), which flags hate, harassment, violence, self-harm and sexual content.
* **AI photo review (OpenAI)** — checks all listing photos in a single request to the same free endpoint.
* **Excessive capitals** — adds risk points when a field is written mostly in capitals (risk signal only, never a block).

**Submission gates**

* **Verified-vendor bypass** — listings from vendors marked with HivePress's native Verified checkbox skip every check.
* **Submission limit** — caps how many listings each vendor can submit within 24 hours (editing does not count).

**Admin visibility**

* A sortable **Moderation** column on the listings screen showing each listing's risk score and held state.
* A **Moderation** meta box on the listing edit screen with the score, the threshold in force, and the per-signal breakdown.
* A one-click **starter blocklist import** button on the settings screen (common profanity, with whole-word patterns for terms that appear inside innocent words).

**Fail-open by design**

If the OpenAI API is unreachable, times out, or errors, submissions proceed unchecked — a moderation outage never takes down listing submission.

== Installation ==

1. Install and activate [HivePress](https://wordpress.org/plugins/hivepress/) (required).
2. Upload the plugin folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
3. Activate it through the **Plugins** screen.
4. Configure it under **HivePress → Settings → Listings → Automated Moderation**.
5. For AI text/photo review, add your OpenAI API key under **HivePress → Settings → Integrations**.

== Frequently Asked Questions ==

= Does this check listings edited in wp-admin? =

No. Admin-side edits in wp-admin bypass the frontend forms and are not checked. Frontend edits by logged-in admins ARE checked.

= Why does blocking "class" also block "classic"? =

Keywords match inside longer words. For whole-word matching, use Blocked Patterns with `\b` boundaries, e.g. `\bclass\b`.

= Are obfuscated contact details detected? =

Contact details written as words ("oh seven one two", "name at gmail dot com") are not detected; add Blocked Patterns for those. The character-evasion option catches accents, invisible characters and common leet substitutions for your keyword lists.

= What data is sent to OpenAI? =

When AI text review is enabled, the listing text is sent to OpenAI's Moderation endpoint. When AI photo review is enabled, the public URLs of the listing photos are sent, and OpenAI fetches the photos from your site (which must be publicly reachable). Moderation calls are free, but require an OpenAI API account. Disclose this data sharing in your privacy policy.

= Why wasn't my regex pattern saved as expected? =

WordPress strips angle brackets from settings textareas on save, so regex assertions containing `<` (e.g. lookbehinds) cannot be used. Invalid patterns are skipped silently, never fatal. Escape a literal `~` as `\~`.

= What happens on uninstall? =

All plugin settings and hidden fingerprint/score meta are removed. The OpenAI API key is deliberately kept, because its option name is shared with other OpenAI integrations; clear the field on the Integrations tab before uninstalling if you want it gone.

= Can I customise the risk weights? =

Yes, via the `hpalm_risk_weights` filter:

	add_filter( 'hpalm_risk_weights', function( $w ) {
		$w['url'] = 30;
		return $w;
	} );

Default weights: phone 25, email 25, website 15, excessive capitals 15, duplicate title 40, duplicate description 40, AI text flag 50, AI photo flag 50. Suggested threshold: 21.

== Changelog ==

= 1.3.4 =
* First public release to the HivePress community.
* Blocked keywords, regex patterns and character-evasion catching.
* Phone number, email address and website address detection, each blockable or scoreable.
* Duplicate title and description detection via background-computed fingerprints.
* AI text and photo review through OpenAI's free Moderation endpoint (fails open).
* Risk scoring with a native HivePress pending hold above the configured threshold.
* Verified-vendor bypass and a per-vendor 24-hour submission limit.
* Sortable Moderation column, listing meta box, and one-click starter blocklist import.
