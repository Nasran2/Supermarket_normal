# Appearance and branding

Set `COLORS=true` in `.env` and clear the configuration cache to enable **Settings → Appearance & branding**. Its card, sidebar link, page and saving require the existing Business settings permission. `COLORS=false` or a missing variable hides all entry points and makes direct viewing/saving return 404. Saved appearance values continue to apply while editing is hidden.

## Names and login text

- **Main name** controls the sidebar name. Blank uses the business name.
- **Second name / subtitle** controls the small text below it. Blank hides it.
- **Login brand heading** replaces the supermarket POS heading. Blank uses the main name followed by POS.
- **Login welcome title** replaces “Welcome back” and is required.
- **Login description** replaces the sentence below the title and may be blank.

The display name does not change the business details printed on bills. Maintain those through Business information.

## Colors

Use a picker or enter six-digit `#RRGGBB`. Separate controls set app accent, main-name text, second-name text, login accent, background, welcome-title text and description text. Buttons and navigation use the app accent. Icon/button foregrounds are chosen for the selected accent.

## Icon, image and saving

Choose **Business logo**, **Built-in icon** or **Custom login image**. Selecting a built-in icon switches to icon mode. Icons include basket, bag, store, cart, scanner, receipt, building, package and shield. Upload PNG, JPG or WEBP up to 2 MB for a custom image. A missing image falls back to the selected icon. The image is independent of both bill logos.

Replacing/removing an image is applied on save. **Reset appearance to defaults** restores form values and retains the uploaded image; it also needs saving. **Cancel** discards unsaved edits. Live preview updates immediately; other screens use **Save changes** and a refresh.

Validation, audit history, upload cleanup and DEMO restrictions also apply. No migration or reseeding is required.
