# Video.js 8.23.4

- Upstream package: `video.js`, version `8.23.4`
- Package: `https://registry.npmjs.org/video.js/-/video.js-8.23.4.tgz`
- License: Apache-2.0; upstream license is included as `LICENSE`.
- Included browser assets: upstream `dist/video.min.js`, unchanged, and `dist/video-js.min.css` with only its embedded WOFF font extracted to the neighboring local `video-js.woff` file. The stylesheet font URL is rewritten to that same-origin file so Arcanorum's `font-src 'self'` policy remains intact.
- npm registry SHA-512 integrity: `sha512-qI0VTlYmKzEqRsz1Nppdfcaww4RSxZAq77z2oNSl3cNg2h6do5C8Ffl0KqWQ1OpD8desWXsCrde7tKJ9gGTEyQ==`.
- SHA-256 `video.min.js`: `acd0a81a4cc78928f1530598762ed783ee916674bf3a0bf2a6c26a6c0b16ed4b`.
- SHA-256 upstream `video-js.min.css`: `69933a9ee9f1c6c0152919103522e86222124964e2195d28eb2d0c043c26f59c`.
- SHA-256 local `video-js.min.css` with same-origin font URL: `57aa86c4578c3091dcf53cd8f27f6fd42a1e509c87a5c4ce665b6461b1bd0a4f`.
- SHA-256 extracted `video-js.woff`: `46d5222f85688002d5b62a53790a6d7d799282dd836b8346c80178bff0fbf3cf`.
- Served by Arcanorum's own asset pipeline; no third-party browser request is needed.
