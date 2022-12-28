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

## Running Transcodes

Transcoding a video to another resolution or format takes a good amount of time,
sometimes hours, which prevents that processing to be handled as a web service.
Instead, the extension implements an asynchronous job, named webVideoTranscode,
which you must be running regularly as your web server user.

The job can be run using the MediaWiki `maintenance/run.php` utility (do not
forget to su as a webserver user):

```
  php run.php runJobs --type webVideoTranscode --maxjobs 1
  php run.php runJobs --type webVideoTranscodePrioritized --maxjobs 1
```

Exclude these jobs from the default tasks your webserver executes
by setting the following options in your `LocalSettings.php`.

```
$wgJobTypesExcludedFromDefaultQueue[] = 'webVideoTranscode';
$wgJobTypesExcludedFromDefaultQueue[] = 'webVideoTranscodePrioritized';
```

## Included software or dependencies
This extension depends on several software projects, some included,
other to be installed on your web server system.

### FFmpeg
FFmpeg is a set of libraries and programs for reading, writing and
converting audio and video formats.

We use ffmpeg for two purposes:
 - creating still images of videos (aka thumbnails)
 - transcoding between WebM, Ogg and/or H.264 videos

Wikimedia currently uses ffmpeg as shipped in Debian 10.
For best experience use that or any later release from https://ffmpeg.org

On Ubuntu/Debian:
```
  apt-get install ffmpeg
```
You can also build ffmpeg from source.
Guide for building ffmpeg with H.264 for Ubuntu:
https://ffmpeg.org/trac/ffmpeg/wiki/UbuntuCompilationGuide

Some old versions of FFmpeg had a bug which made it extremely slow to seek in
large theora videos in order to generate a thumbnail. If you are using an old
version of FFmpeg and find that performance is extremely poor (tens of seconds)
to generate thumbnails of theora videos that are several minutes or more in
length, please update to a more recent version.

In MediaWiki configuration, after the require line in LocalSettings.php, you
will have to specify the FFmpeg binary location with:

```
    $wgFFmpegLocation = '/path/to/ffmpeg';
```

Default being `/usr/bin/ffmpeg`.

For more information about FFmpeg visit:
https://ffmpeg.org

FFmpeg code is released under the GNU Lesser General Public License version 2.1
4 or later (LGPL v2.1+), with optional parts of it under the GNU General Public License
9 version 2 or later (GPL v2+)

### PEAR File_Ogg

Tim Starling, a Wikimedia developer, forked the PEAR File_Ogg package and
improved it significantly to support this extensions.

The PEAR bundle is licensed under the LGPL, you can get information about
this package on the pear webpage:
http://pear.php.net/package/File_Ogg

### getID3

getID3 is used for metadata of WebM files. It is marked as a dependency
to be installed via composer.

getID3() by James Heinrich <info@getid3.org>
available at http://getid3.sourceforge.net
or http://www.getid3.org/

getID3 code is released under the GNU GPL:
http://www.gnu.org/copyleft/gpl.html
