# Bilingual UI and content

Nasaq AI supports English (`en`) and Arabic (`ar`) from the first application
shell. All visible product strings live in translation catalogs.

## Direction

- `document.documentElement.lang` and `dir` change together.
- English uses `ltr`; Arabic uses `rtl`.
- Layout uses CSS logical properties (`inline-start`, `margin-inline`) so the
  navigation and panels mirror naturally.
- Node graph coordinates remain stable when language changes; control layout and
  directional icons adapt without rewriting graph data.
- URLs, code, identifiers, file names, and numbers use isolated LTR spans inside
  Arabic text where necessary.

## Persistence

The locale is saved immediately in local storage and synchronized to the
authenticated user's `preferred_locale`. Server-rendered/API error codes are
translated by the frontend; safe English diagnostics may appear in an expanded
technical-details region.

## AI output

Researcher and Writer accept `en`, `ar`, or `same_as_input`. Demo fixtures exist
in both languages. PDF/DOCX export embeds or uses fonts with Arabic shaping and
right-to-left support; acceptance tests inspect readable Arabic content rather
than merely the presence of Arabic bytes.

## QA checklist

- No untranslated visible key or hard-coded UI label.
- Navigation, dialogs, forms, canvas sidebars, logs, output previews, and empty
  states work in both directions.
- Focus order follows visual order.
- Status combines localized text with an icon; color is never the only signal.
- Mobile run/output pages remain usable at 360 CSS pixels.

