# TimedMediaHandler-wgl
This extension provides a media handler for the Ogg, WebM, mp4 container format.

This is a fork of the [TimedMediaHandler](https://www.mediawiki.org/wiki/Extension:TimedMediaHandler) extension, designed for use on [Weird Gloop](https://weirdgloop.org) wikis, with the following changes:

* Removed VideoJS player and using raw `<audio>` and `<video>` HTML tags instead
* No embedding videos in iframes/modals/popovers
* Removed support for TimedText and closed captioning
* Removed `ogv.js` compatibility shim
* Added `autoplay` parameter for files: when enabled, autoplays and mutes media
* Added `nocontrols` parameter for files: when enabled, shows no controls
* Video transcoding is disabled by default (change `$wgEnabledTranscodeSet` to enable)
* Video elements have the `playsinline` attribute by default, for autoplay compatibility on iOS Safari

## Rationale behind this fork

On our wikis, especially the [RuneScape Wiki](https://runescape.wiki), we don't really have a need for custom player UIs that are
supplied with TimedMediaHandler by default. Both mwembed and VideoJS players are unnecessary fluff for us, compared to
letting the browser determine how to display the video by simply outputting `<audio>` and `<video>` tags.

Similarly, most of the media files on our wikis are either music (which only consists of instruments derived from MIDI),
or sound effects. Therefore, we have no use in closed caption support (for now).

## Installing
First, ensure that you have installed [ffmpeg](https://ffmpeg.org) and [Composer](https://www.mediawiki.org/wiki/Composer).

After you installed this extension, add the following to the end of your
`LocalSettings.php` to enable it:

```
  wfLoadExtension( 'TimedMediaHandler' );
  
  // Change the following line as appropriate
  $wgFFmpegLocation = '/usr/bin/ffmpeg';
```

Then, run the following:

* Run the `maintenance/update.php` update script
* Install Composer dependencies using `composer install --no-dev` inside of the `extensions/TimedMediaHandler` directory.

## Configuration
For the most part, the configuration is the same as the original [TimedMediaHandler](https://www.mediawiki.org/wiki/Extension:TimedMediaHandler#Configuration), but with the following changes:

* `$wgTmhEnableMp4Uploads` is enabled by default.
* `$wgEnableIframeEmbed` was removed, as it is not used.
* `$wgTimedTextNS` and `$wgTimedTextForeignNamespaces` was removed, as captions support has been dropped for now.