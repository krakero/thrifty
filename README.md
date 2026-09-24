# Thrifty

A native iOS app for spotting resellable finds at garage sales and thrift stores. Point the camera at a table of stuff (or upload a photo or
video) and Thrifty samples frames, asks an OpenAI model to identify what's for sale, researches retail and resale prices, and streams the finds
into a live feed with bounding boxes, price ranges, and comparables.

> **Credit:** Thrifty is a native mobile port of **[Yard Sale Gold](https://github.com/wesbos/yard-sale)** by
> **[Wes Bos](https://github.com/wesbos)**. The features, agent workflow, valuation prompt and data model all come from his original web app;
> this project rebuilds it as a NativePHP Mobile iOS app. Go check out the original.

## Features

- **Scan**: live camera scanning at a configurable interval (1–30s), snapshots, and photo/video uploads (HEIC included), with up to 4 frames
  analysed in parallel.
- **AI valuation**: each frame runs an agent on the OpenAI Responses API (`gpt-5.6-luna`) with web search, an optional eBay active-listings tool,
  and a previous-scans lookup to de-duplicate items across frames and sessions.
- **Live feed**: finds stream in with a chime and haptic, repeat sightings bump to the top, and stats track frames, items, searches and model calls.
- **History**: searchable, paginated inventory of every find.
- **Find detail**: the annotated frame with tappable bounding boxes, tag/retail/active/sold prices, a resale range, comparables, share-as-image and delete.
- **Agent activity**: the full sanitized audit trail for each frame (prompts, tool calls, raw responses, usage).
- **Settings**: find criteria with presets, concurrency, scan frequency, your OpenAI key and optional eBay credentials.

Everything runs on the device: frames, finds and settings live in on-device SQLite and storage, and the phone calls OpenAI directly with your key.

## Stack

- [Laravel](https://laravel.com) 13 + [NativePHP Mobile](https://nativephp.com) v4 (SuperNative: native SwiftUI screens driven by PHP)
- `thrifty/camera`: a local NativePHP plugin (`packages/thrifty/camera`) providing the live camera preview, interval/snapshot capture, video frame
  extraction, HEIC import, find chime, share card, and an accessible `<native:thrifty-pressable>` element
- [Pest](https://pestphp.com) for tests

## Getting started

Requirements: macOS with Xcode, PHP 8.4, Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

In `.env`, set:

```dotenv
APP_NAME=Thrifty
NATIVEPHP_APP_ID=com.yourname.thrifty
NATIVEPHP_START_URL=/
```

Then build and run on the iOS Simulator or a connected iPhone:

```bash
php artisan native:run ios
```

Open **Settings** in the app and add your OpenAI API key. Optionally add eBay client credentials to enable active-listing comparables.

> The Simulator has no camera. Use **Upload** to scan photos or videos from the Simulator's photo library
> (`xcrun simctl addmedia booted photo.jpg`), and test live scanning on a real device.

## Tests

```bash
php artisan test
```

## Project layout

| Path | What |
|---|---|
| `app/NativeComponents` | Screens (Scan, History, ItemDetail, AgentActivity, Settings) and the tab layout |
| `resources/views/native` | EDGE Blade views for the screens |
| `app/Agent`, `app/Async` | The valuation agent (Responses API loop, tools, dedupe, persistence) and the `AnalyzeFrame` async task |
| `app/Scanning` | Scan state, frame dispatching, video draining, and file cleanup |
| `packages/thrifty/camera` | The native camera plugin (Swift + PHP) |

## Notes and limitations

- iOS only.
- NativePHP's async pool has 4 slots, so parallel analysis is capped at 4 (the web original allowed more).
- API keys are stored in the on-device database, encrypted with the app key. They are not in the iOS Keychain.
- Analysis sends frames to OpenAI (and search queries to eBay, if configured) using your own credentials and account.

## License

See [LICENSE.md](LICENSE.md). Fonts in `resources/fonts` are licensed under the SIL Open Font License 1.1 (see the accompanying `*-OFL.txt` files).
