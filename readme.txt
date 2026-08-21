=== Automated Listing Moderation for HivePress ===
Contributors: chrisb
Tags: hivepress, moderation, spam, listings, marketplace
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blocks or risk-scores HivePress listing submissions containing blocked words, contact details, duplicate content or AI-flagged text and photos.

== Description ==

Automated Listing Moderation adds a configurable moderation layer to the HivePress listing submit and edit forms. Everything is configured under **HivePress → Settings → Listings → Automated Moderation** (the OpenAI key lives on the **Integrations** tab).

**Two enforcement levels**

* **Block**: the form is refused with a specific inline error before anything is saved.
* **Risk score**: soft signals accumulate points; if the total reaches your threshold the listing is accepted but **held as Pending** for admin review, using HivePress's native moderation flow (native pending status, admin-menu pending badge, and, for new submissions, HivePress's own admin notification email). When you approve a held listing, the native approval email still reaches the vendor. A held *edit* of an already-published listing produces no admin email, because HivePress only sends one for new submissions; the pending badge still shows it.

There is deliberately **no auto-delete tier**: heuristics can false-positive, so a human always makes the final negative decision.

**Detectors**

* **Blocked keywords**: words or phrases, comma- or newline-separated, matched case-insensitively (including inside longer words).
* **Blocked patterns**: regular expressions, one per line, for whole-word matching and advanced rules.
* **Disguised spellings**: optionally re-checks your lists against copies of the text with accents removed, invisible characters stripped, and common leet substitutions reversed, so blocking "shit" also catches "sh1t" and "$hit". This never blocks a word you have not blocked yourself, but it does mean accented spellings of your blocked words match too: block "cafe" and "café" is blocked as well.
* **Phone numbers**: sequences of 9 to 14 digits with optional spacing, dashes, brackets, or a leading +. Prices, years, dates and IP addresses are not affected, but any other long run of digits is (see the FAQ).
* **Email addresses**: anything shaped like an email address.
* **Website addresses**: http/https/www links plus bare domains with common extensions.
* **Duplicate titles and descriptions**: compares precomputed fingerprints (case, formatting and spacing insensitive) against live and pending listings; one small query per submission. Existing listings are fingerprinted automatically in the background.
* **AI text review (OpenAI)**: sends listing text to OpenAI's Moderation endpoint (omni-moderation-latest), which flags hate, harassment, violence, self-harm and sexual content.
* **AI photo review (OpenAI)**: sends the photos themselves, up to the first 10, each checked separately. Flags sexual, violent and self-harm imagery. Works on any site, including one that is not publicly reachable (the exception is photos kept only in cloud storage, where a web address is sent instead and OpenAI must be able to reach it). Videos are never sent or checked.
* **Excessive capitals**: adds risk points when a field is written mostly in capitals (risk signal only, never a block).

**Submission gates**

* **Verified-vendor bypass**: listings from vendors marked with HivePress's native Verified checkbox skip every check, including the submission limit.
* **Submission limit**: caps how many new listings each vendor can add in any 24 hours. Only live and awaiting-review listings count towards it, and editing an existing listing never does.

**Admin visibility**

* A sortable **Moderation** column on the listings screen showing each listing's score in points and what became of it: held for review, approved by you, rejected by you, or published as normal.
* A **Moderation** panel on the listing edit screen that says the score in points, whether it reached your limit, what was found and what each thing was worth, and what to do next.
* **Starter blocklists** on the settings screen, in four groups you add with one click: Profanity, Sexual content, Slurs and hate speech, and Scams and off-platform selling. Around 120 entries in total. Each is split between keywords and whole-word patterns so that ordinary words and British place names are not caught, and nothing is saved until you review the lists and click Save Changes.

**Fail-open by design**

If the OpenAI API is unreachable, times out, or errors, submissions proceed unchecked, so a moderation outage never takes down listing submission. Because that would otherwise be invisible, a warning appears on the settings screen whenever AI review is switched on but the calls are failing, naming the reason, so you always know whether it is actually working.

**Automatic updates**

New versions are delivered straight from the plugin's GitHub releases. Update notifications, "Check for updates", and one-click updating all appear on the normal WordPress Plugins screen, just like a plugin installed from wordpress.org.

== Installation ==

1. Install and activate [HivePress](https://wordpress.org/plugins/hivepress/) (required).
2. Upload the plugin folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
3. Activate it through the **Plugins** screen.
4. Configure it under **HivePress → Settings → Listings → Automated Moderation**.
5. For AI text/photo review, add your OpenAI API key under **HivePress → Settings → Integrations**.

== Frequently Asked Questions ==

= Does this check listings edited in wp-admin? =

No. Admin-side edits in wp-admin bypass the frontend forms and are not checked, and the same applies to listings created programmatically by other plugins or import tools. Frontend edits by logged-in admins ARE checked. Listings created by importers still receive duplicate-detection fingerprints, so future submissions are checked against them.

= Why does blocking "class" also block "classic"? =

Keywords match inside longer words. For whole-word matching, use Blocked Patterns with `\b` boundaries, e.g. `\bclass\b`.

= Can I change the wording of anything this plugin displays? =

Yes. Every message, label and tooltip is translatable, so a translation plugin such as Loco Translate can reword any of them, including into American spellings, without editing code. Translations belong in WordPress's own languages folder (Loco calls this the "System" location), which survives plugin updates.

= Why was a barcode or reference number treated as a phone number? =

Phone detection looks for a run of 9 to 14 digits, and it cannot tell a phone number from any other long number. Prices, years, dates and IP addresses are excluded, but a 13-digit barcode or ISBN, or a long order or product reference, will match. On a site where those are normal, set Phone Numbers to "Add to risk score" instead of "Block submission": a genuine listing is then held for your review rather than refused outright, and you can approve it in a click. If you need to allow one specific format, leave phone detection off and write a Blocked Pattern that matches only the shapes you actually want to stop.

= Are obfuscated contact details detected? =

Contact details written as words ("oh seven one two", "name at gmail dot com") are not detected; add Blocked Patterns for those. The character-evasion option catches accents, invisible characters and common leet substitutions for your keyword lists.

= What happens while a listing is held for review? =

It sits as Pending in your dashboard until you approve or reject it, exactly like a listing held by HivePress's own moderation setting. Approve or reject it from the Listings screen, using the row actions or Quick Edit, which is HivePress's usual approval route and avoids any required listing field blocking the full editor.

Three things follow from using the native flow, all core HivePress behaviour rather than anything this plugin adds: the vendor can still edit the listing's text but cannot upload new photos to it until it is approved; rejecting a held listing by moving it to the bin sends the vendor HivePress's "listing rejected" email, so bulk-binning held spam will email each of those vendors; and binning a held listing also bins the vendor's profile if that profile is still a draft.

= AI photo review is on, but an obviously unsuitable photo was accepted. Why? =

First check **HivePress → Settings → Listings** for a warning: if the connection to OpenAI is failing, the warning names the reason and no photo was ever checked.

If there is no warning, the photo was checked and OpenAI did not consider it a breach. The endpoint judges sexual, violent and self-harm imagery, and it is deliberately conservative, so borderline pictures pass. It cannot judge anything else: harassment, hate and illicit content are assessed from text only, never from a picture.

Two other things to confirm: only the first 10 photos on a listing are checked, and a detector set to "Add to risk score" does nothing at all until a Risk Threshold is entered.

If you are on a version before 1.6.2, photo review did not work on listings with more than one photo. Update.

= What data is sent to OpenAI? =

When AI text review is enabled, the listing text is sent to OpenAI's Moderation endpoint. When AI photo review is enabled, the listing photos themselves are sent (a resized copy, up to ten per listing; on sites whose photos live only in cloud storage, the photo's web address is sent instead). Videos are never sent. The moderation calls themselves cost nothing, but OpenAI refuses every API request on an account with no credit or payment method, so a brand new account will not work until credit is added. Disclose this data sharing in your privacy policy.

= Does duplicate detection work with a generated title format? =

Partially. If your site uses HivePress's title format feature (where listing titles are built from attributes rather than typed by the vendor), there is no title field on the submission form for this plugin to check, so the Duplicate Titles detector has nothing to inspect and will not fire. Duplicate Descriptions is unaffected. On such a site, rely on the description check; identical generated titles are expected there anyway.

= Why wasn't my regex pattern saved as expected? =

WordPress strips angle brackets from settings textareas on save, so regex assertions containing `<` (e.g. lookbehinds) cannot be used. Invalid patterns are skipped silently, never fatal. Escape a literal `~` as `\~`.

= What happens when I delete the plugin? =

By default, nothing is lost: your settings, risk scores, held-for-review markers and duplicate-detection fingerprints are all kept, so you can reinstall later and carry on where you left off. WordPress itself always warns that deleting a plugin "will also delete its data", but that warning does not apply here unless you tick "Delete all data when this plugin is deleted" on the settings screen first. With the box ticked, everything the plugin created is permanently removed. The OpenAI API key is kept either way, because its option name is shared with other OpenAI integrations; clear the field on the Integrations tab if you want it gone. Deactivating never removes anything.

= How do updates work? =

The plugin checks its GitHub releases for a newer version and shows updates on your Plugins screen, the same as any other plugin. No account or licence key is needed. This uses WordPress's native update API (the `Update URI` header), with no third-party library. A "Check for updates" link on the plugin row lets you check on demand.

= Can I customise the risk weights? =

Yes, via the `hpalm_risk_weights` filter:

	add_filter( 'hpalm_risk_weights', function( $w ) {
		$w['url'] = 30;
		return $w;
	} );

Default weights: phone 25, email 25, website 15, excessive capitals 15, duplicate title 40, duplicate description 40, AI text flag 50, AI photo flag 50. Suggested threshold: 21.

== Changelog ==

= 1.6.9 =
* Three new hooks so Notifications for HivePress can tell a vendor their listing is being held, and
  tell you why: `hpalm/listing_held` (with the score, the signals, and whether it came from the
  submission or the later photo check), `hpalm/limit_reached` and `hpalm/submission_blocked`.
* New - an `HPALM_VERSION` constant, so another plugin can tell this one is installed without
  loading anything.
* No change to what is blocked, held or scored.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.

= 1.6.8 =
* Fixed: submitting a listing could hang for half a minute, and on a busy site that was enough to make the whole site time out for everyone. Photo review sends one request per photo to OpenAI, which downloads each picture itself, and all of that happened while the vendor sat waiting on the submit button. On a six-photo listing it measured 21 seconds at ordinary API speeds and 32 seconds at slow ones. Every submission in progress occupies one of the small number of PHP processes your host gives you, so a handful at once left nothing to serve anybody else and visitors got 504 errors with nothing to connect them to this plugin.
* Changed: photos are now reviewed in the background, moments after the listing is submitted, instead of during it. Submitting is immediate again. A listing whose photo is refused is held as Pending exactly as before and never appears publicly, so nothing gets past the check that would not have before. The one difference is when the vendor finds out: they are told on review rather than at the moment they press submit.
* Fixed: the photo review time limit was not the limit it claimed. It was checked only before each photo, so the last one could start with a fraction of a second left and then run its own full timeout on top, turning a stated 20 seconds into 32. Each photo is now given only the time actually remaining.
* Changed: the text check, which is quick because it sends no pictures, still runs during submission but now gives up after 3 seconds instead of 8.

= 1.6.7 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.6.6 =
* Changed: the support link on the Plugins screen now reads "Donate" with a star icon, matching every other extension in the range.
* Removed: the thank-you line under the settings form. The "Donate" link on the Plugins screen and in the plugin details popup is the only place the ask appears now, so it never interrupts you while you are configuring the plugin.

= 1.6.5 =
* Fixed: the Moderation panel said things like "Risk score: 65 of 21", which reads as a fraction with a bigger top than bottom and means nothing. 21 was never a maximum, it is the point at which a listing stops being published. The panel now says the score in points, whether that reached your Risk Threshold, and what actually became of the listing.
* Fixed: a scored listing that you had already approved or rejected showed a bare number and nothing else, so there was no way to tell an approved listing from one still waiting. Every scored listing now carries a plain label: held for review, held then approved, held then rejected, held now a draft, or published as normal. The same labels appear in the Moderation column.
* Fixed: setting a check to "Add to risk score" did nothing at all until a Risk Threshold was entered, and no screen said so. The option now reads "Add to risk score (set a Risk Threshold below first)" until you set one.
* Fixed: "No risk signals were recorded" sounded like a clean bill of health, when it also appears for listings added in the dashboard or by an import, which are never checked at all. It now says which of those it means.
* Improved: the Moderation column showed a bare number under a heading that said only "Moderation". It now reads "65 points", and rows with nothing recorded say so instead of showing a dash.
* Improved: the breakdown now names what was found in plain words ("Another listing has the same title" rather than "Duplicate title") and puts the unit on every line.
* Improved: a held listing now tells you what to do next, including the Quick Edit route for when a required listing field stops you saving from the edit screen.
* Improved: the Moderation panel now says when your Risk Threshold has changed since a listing was scored, rather than quietly quoting the old figure as though it were current.
* Fixed: a vendor who hit the submission limit was told they had reached "the maximum" without being told what it was. The message now names the number.
* Fixed: the refusal shown for a blocked word said "to continue with submitting your listing" even when the vendor was editing a listing they already had.
* Improved: the two AI refusals now say what the check looks for and what to do about it, and the photo one points out that a photo already on the listing can be the cause, not only one just added.
* Fixed: the Disguised Spellings setting claimed accented text is never blocked, which is the opposite of what it does. Blocking "cafe" does also block "café", and the setting now says so.
* Fixed: the note about percent signs was wrong. WordPress removes a percent sign only when the next two characters are digits or the letters a to f, not any two letters or digits.
* Improved: the verified-vendor setting now mentions that it also exempts those vendors from the submission limit, and the submission limit says which listings count towards it.
* Improved: the OpenAI key field now says where to get a key, and that it is only needed if you use the AI checks.
* Improved: plainer wording throughout the settings, with developer terms such as "fingerprint", "leet-speak", "endpoint" and "tier" removed.
* Added: a quiet "buy me a coffee" link at the foot of the settings, on the plugin's row on the Plugins screen, and in its View details popup.

= 1.6.4 =
* First public release to the HivePress community. Every earlier version number was a private build.
* Fixed: every backslash was being stripped out of Blocked Patterns, which quietly disabled whole-word matching. Saving `\bcash\s*only\b` stored `bcashs*onlyb`, so the pattern matched nothing at all, and the settings box showed the damaged version afterwards. It happened on the way in and on the way out, so simply opening the Listings tab and saving an unrelated setting was enough to ruin every pattern already there. This affected the four starter blocklists too, since their whole-word entries are what stop ordinary words and British place names being caught. If you wrote any patterns under an earlier build, open the settings screen and check them: any that lost their backslashes need retyping.
* Fixed: AI photo review never worked on a listing with more than one photo. Every photo was sent to OpenAI in a single request, and that endpoint accepts exactly one image per request, so the request was refused and the listing was accepted unchecked. A listing with one photo worked correctly, which is why this went unnoticed. Each photo is now checked separately, and checking stops at the first one flagged.
* Fixed: if any photo cannot be checked, the listing is no longer treated as having clean photos. Photo review now only reports "clean" when every photo actually got a verdict.
* Fixed: AI photo review often checked nothing at all. It used to send OpenAI a link to each photo and rely on OpenAI downloading it, which fails on any site that is not publicly reachable, and on hosts with hotlink protection, a firewall, or a CDN rule that refuses requests not made by a browser. The listing was then accepted, looking exactly as though the photos had been checked and found fine. The photos themselves are now sent, so the check works everywhere. Sites that store uploads in cloud storage, where there is no local file, still use the previous method.
* Fixed: AI text review only read the first 8,000 characters of a listing, so anything a vendor wrote past that point was never checked. On a listing with a long description, or with several custom attributes, violating text could sit past the cut and go straight through. The limit is now 50,000 characters, which is beyond what HivePress's own fields allow.
* Added: the starter blocklist is now four separate lists you choose between, around 120 entries in all, instead of one list of 23. Profanity, Sexual content, Slurs and hate speech, and Scams and off-platform selling, each added with its own button so you only import what suits your site. Developers can change them with the `hpalm_starter_lists` filter.
* Fixed: five starter-list entries blocked innocent listings because plain keywords match inside longer words. "twat" matched Lightwater in Surrey, "bitch" matched Bitchfield in Lincolnshire, "bugger" matched debugger, "milf" matched Milford Haven and every other Milford, and "zelle" matched Gazelle bicycles. All five now use whole-word matching, and every entry in the Slurs and hate speech list is whole-word matched as its description always said. If you imported an earlier private build's list, replace those entries.
* Fixed: with videos allowed on listings (a HivePress display setting), AI photo review was sending each video's address to OpenAI as though it were a photo. The request always failed, wasting up to 8 seconds of the review budget per video, occupying photo slots, and leaving a false connection warning on the settings screen. Videos are now excluded before review starts; only photos are ever sent.
* Fixed: a listing whose photos could not all be checked no longer loses its settings-screen warning just because a later photo in the same gallery was checked successfully. The order of the photos no longer decides whether you are told.
* Fixed: AI photo review did not run at all on a listing with no text to scan, which is a real shape on sites where titles are generated from attributes and the description is optional. Photo review no longer depends on the listing having any text.
* Fixed: duplicate detection now re-checks for missed listings every time the plugin is activated, so listings added while it was deactivated (during a migration, or while chasing a plugin conflict with all plugins off) are fingerprinted on reactivation instead of staying invisible to the duplicate checks forever.
* Fixed: translated text containing an apostrophe or quotation mark could display as raw HTML code in the Moderation meta box and on the starter-list buttons.
* Documented: phone detection matches any run of 9 to 14 digits, so barcodes, ISBNs and long reference numbers match too. The setting now says so and suggests "Add to risk score" for sites where those are normal.
* Documented: the OpenAI section on the Integrations tab now states, in plain sight rather than only in a tooltip, that OpenAI needs a payment method or purchased credit on the account before any request is allowed.
* Improved: when AI photo review cannot run because OpenAI was unable to download your photos, the settings screen now says exactly that, instead of reporting a generic error. OpenAI fetches each photo from your site by its web address, so this is what you see if the site is not publicly reachable, or if a firewall, hotlink protection or a CDN rule blocks non-browser requests. Previously this looked identical to "the photos were checked and were fine".
* Fixed: an administrator publishing a held listing from the WordPress dashboard is no longer overruled by the plugin's own hold. This mattered most for a held listing that had since expired into drafts, which previously could not be published at all.
* Fixed: upgrading from an earlier private build could clear every Automated Moderation setting instead of carrying it across.
* Added: a warning on the settings screen when AI review is switched on but the calls to OpenAI are failing, naming the reason. Submissions still go through unchecked, as before, but you are no longer left believing AI review is running when it is not. The commonest cause is an OpenAI account with no credit: the moderation calls cost nothing, but OpenAI refuses every request until the account has a payment method or purchased credit.
* Improved: the held-for-review badge was pale yellow on a white background and hard to read. It is now a darker amber on a tinted pill, comfortably above the accepted contrast minimum.
* Improved: the AI warning distinguishes an account with no credit from ordinary rate limiting, and stops asking for an API key once one has been entered.

= 1.5.0 =
* Pre-release build, tested on staging only and never published.
* Changed: deleting the plugin now keeps your settings and moderation records by default; a new "Delete all data when this plugin is deleted" setting makes removal opt-in. Settings saved by earlier builds are migrated automatically.
* Fixed: a listing held for review could still reach your live site if it was published by something other than your approval, such as a vendor unhiding it or a paid listing package completing at checkout. Any held listing is now returned to the review queue unless you approve it yourself.
* Fixed: with website address blocking switched on, editing a listing that uses booking calendar sync could be refused because of the calendar link the site generates itself. Only text a vendor actually typed is checked now.
* Fixed: detectors set to "Add to risk score" were still being run, including the calls to OpenAI, when no Risk Threshold had been entered, even though the result could not be used. They now stay switched off until a threshold is set.
* Fixed: a rejected listing that you restore from the trash now returns to the pending review queue instead of landing in drafts.
* Improved: the held-for-review badge now uses HivePress's own status pill and only shows while the listing really is awaiting review.
* Improved: requests to OpenAI and GitHub no longer carry your site address and WordPress version; they identify the plugin only.
* Improved: duplicate-detection fingerprinting of existing listings now also completes in the background, without needing the dashboard to be opened.
* Improved: setting labels now carry their units, and every placeholder can be reworded through translation.

= 1.4.2 =
* Fixed: listings created by an importer (demo content, WXR files, migration tools) never got a duplicate-detection fingerprint, so the duplicate checks stayed blind to them permanently. Fingerprinting now runs on every save regardless of how the listing was created.
* Improved: the checks that identify a listing are now type checks rather than method checks, so they cannot silently switch a feature off if HivePress changes how its model getters are defined.
* Improved: sorting by the Moderation column now works when the listings query asks for several post types at once.
* Documented: photos cannot be uploaded while a listing is held for review, and rejecting a held listing also trashes a still-draft vendor profile. Both are core HivePress behaviours that follow from using its native moderation flow.
* Translations now follow the same convention as official HivePress extensions, loading from WordPress's own languages folder so they survive plugin updates.

= 1.4.1 =
* Fixed: the verified-vendor bypass and the submission limit resolved the wrong vendor and author on some submissions (listing relations return model objects, not IDs).
* Fixed: AI photo review received no photos during the normal submit flow; photo IDs are now read from the listing itself.
* Added: a Settings quick link on the Plugins screen row.
* Added: descriptions to the Automated Moderation and OpenAI settings sections.
* The update cache is now removed on uninstall.

= 1.4.0 =
* Private build, never published.
* Automatic updates delivered straight from GitHub releases (update notifications and one-click updating on the Plugins screen).
* Blocked keywords, regex patterns and character-evasion catching.
* Phone number, email address and website address detection, each blockable or scoreable.
* Duplicate title and description detection via background-computed fingerprints.
* AI text and photo review through OpenAI's Moderation endpoint (fails open).
* Risk scoring with a native HivePress pending hold above the configured threshold.
* Verified-vendor bypass and a per-vendor 24-hour submission limit.
* Sortable Moderation column, listing meta box, and one-click starter blocklist import.
