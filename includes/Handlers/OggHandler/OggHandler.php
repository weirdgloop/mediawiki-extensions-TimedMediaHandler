<?php

namespace MediaWiki\TimedMediaHandler\Handlers\OggHandler;

use File;
use File_Ogg;
use IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\TimedMediaHandler\TimedMediaHandler;
use Status;
use Wikimedia\AtEase\AtEase;

/**
 * ogg handler
 */
class OggHandler extends TimedMediaHandler {
	private const METADATA_VERSION = 2;

	/**
	 * @param File $image
	 * @param string $path
	 * @return string
	 */
	public function getMetadata( $image, $path ) {
		$metadata = [ 'version' => self::METADATA_VERSION ];

		try {
			$f = new File_Ogg( $path );
			$streams = [];
			foreach ( $f->listStreams() as $streamIDs ) {
				foreach ( $streamIDs as $streamID ) {
					$stream = $f->getStream( $streamID );
					'@phan-var \File_Ogg_Media $stream';
					$streams[$streamID] = [
						'serial' => $stream->getSerial(),
						'group' => $stream->getGroup(),
						'type' => $stream->getType(),
						'vendor' => $stream->getVendor(),
						'length' => $stream->getLength(),
						'size' => $stream->getSize(),
						'header' => $stream->getHeader(),
						'comments' => $stream->getComments()
					];
				}
			}
			$metadata['streams'] = $streams;
			$metadata['length'] = $f->getLength();
			// Get the offset of the file (in cases where the file is a segment copy)
			$metadata['offset'] = $f->getStartOffset();
		} catch ( OggException $e ) {
			// File not found, invalid stream, etc.
			$metadata['error'] = [
				'message' => $e->getMessage(),
				'code' => $e->getCode()
			];
		}
		return serialize( $metadata );
	}

	/**
	 * Display metadata box on file description page.
	 *
	 * This is pretty basic, it puts data from all the streams together,
	 * and only outputs a couple of the most commonly used ogg "comments",
	 * with comments from all the streams combined
	 *
	 * @param File $file
	 * @param false|IContextSource $context Context to use (optional)
	 * @return array|false
	 */
	public function formatMetadata( $file, $context = false ) {
		$meta = $this->getCommonMetaArray( $file );
		if ( count( $meta ) === 0 ) {
			return false;
		}
		return $this->formatMetadataHelper( $meta, $context );
	}

	/**
	 * Get some basic metadata properties that are common across file types.
	 *
	 * @param File $file
	 * @return array Array of metadata. See MW's FormatMetadata class for format.
	 */
	public function getCommonMetaArray( File $file ) {
		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( !$metadata || isset( $metadata['error'] ) || !isset( $metadata['streams'] ) ) {
			return [];
		}

		// See http://www.xiph.org/vorbis/doc/v-comment.html
		// http://age.hobba.nl/audio/mirroredpages/ogg-tagging.html
		$metadataMap = [
			'title' => 'ObjectName',
			'artist' => 'Artist',
			'performer' => 'Artist',
			'description' => 'ImageDescription',
			'license' => 'UsageTerms',
			'copyright' => 'Copyright',
			'organization' => 'dc-publisher',
			'date' => 'DateTimeDigitized',
			'location' => 'LocationDest',
			'contact' => 'Contact',
			'encoded_using' => 'Software',
			'encoder' => 'Software',
			// OpenSubtitles.org hash. Identifies source video.
			'source_ohash' => 'OriginalDocumentID',
			'comment' => 'UserComment',
			'language' => 'LanguageCode',
		];

		$props = [];

		foreach ( $metadata['streams'] as $stream ) {
			if ( isset( $stream['vendor'] ) ) {
				if ( !isset( $props['Software'] ) ) {
					$props['Software'] = [];
				}
				$props['Software'][] = trim( $stream['vendor'] );
			}
			if ( !isset( $stream['comments'] ) ) {
				continue;
			}
			foreach ( $stream['comments'] as $name => $rawValue ) {
				// $value will be an array if the file has
				// a multiple tags with the same name. Otherwise it
				// is a string.
				foreach ( (array)$rawValue as $value ) {
					$trimmedValue = trim( $value );
					if ( $trimmedValue === '' ) {
						continue;
					}
					$lowerName = strtolower( $name );
					if ( isset( $metadataMap[$lowerName] ) ) {
						$convertedName = $metadataMap[$lowerName];
						if ( !isset( $props[$convertedName] ) ) {
							$props[$convertedName] = [];
						}
						$props[$convertedName][] = $trimmedValue;
					}
				}
			}

		}
		// properties might be duplicated across streams
		foreach ( $props as &$type ) {
			$type = array_unique( $type );
			$type = array_values( $type );
		}

		return $props;
	}

	/**
	 * Get the "media size"
	 *
	 * @param File $file
	 * @param string $path
	 * @param false|string|array $metadata
	 * @return array|false
	 */
	public function getImageSize( $file, $path, $metadata = false ) {
		global $wgMediaVideoTypes;
		// Just return the size of the first video stream
		if ( $metadata === false ) {
			$metadata = $file->getMetadata();
		}

		if ( is_string( $metadata ) ) {
			$metadata = $this->unpackMetadata( $metadata );
		}

		if ( isset( $metadata['error'] ) || !isset( $metadata['streams'] ) ) {
			return false;
		}
		foreach ( $metadata['streams'] as $stream ) {
			if ( in_array( $stream['type'], $wgMediaVideoTypes, true ) ) {
				$pictureWidth = $stream['header']['PICW'];
				$parNumerator = $stream['header']['PARN'];
				$parDenominator = $stream['header']['PARD'];
				if ( $parNumerator && $parDenominator ) {
					// Compensate for non-square pixel aspect ratios
					$pictureWidth = $pictureWidth * $parNumerator / $parDenominator;
				}
				return [
					(int)$pictureWidth,
					(int)$stream['header']['PICH']
				];
			}
		}
		return [ false, false ];
	}

	/**
	 * @param string|array $metadata
	 * @param bool $unserialize
	 * @return false|mixed
	 */
	public function unpackMetadata( $metadata, $unserialize = true ) {
		if ( $unserialize ) {
			$metadata = AtEase::quietCall( 'unserialize', $metadata );
		}

		if ( isset( $metadata['version'] ) && $metadata['version'] === self::METADATA_VERSION ) {
			return $metadata;
		}

		return false;
	}

	/**
	 * @param File $image
	 * @return string
	 */
	public function getMetadataType( $image ) {
		return 'ogg';
	}

	/**
	 * @param File $file
	 * @return string
	 */
	public function getWebType( $file ) {
		$baseType = $this->isAudio( $file ) ? 'audio' : 'video';
		$baseType .= '/ogg';
		$streamTypes = $this->getStreamTypes( $file );
		if ( !$streamTypes ) {
			return $baseType;
		}
		$codecs = strtolower( implode( ", ", $streamTypes ) );
		return $baseType . '; codecs="' . $codecs . '"';
	}

	/** @inheritDoc */
	public function verifyUpload( $fileName ) {
		$mimeAnalyzer = MediaWikiServices::getInstance()->getMimeAnalyzer();
		if ( $mimeAnalyzer->getMediaType( $fileName ) !== MEDIATYPE_VIDEO ) {
			return Status::newGood();
		}

		return Status::newFatal( 'timedmedia-ogg-no-video' );
	}

	/**
	 * @param File $file
	 * @return string[]|false
	 */
	public function getStreamTypes( $file ) {
		$streamTypes = [];
		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return false;
		}
		foreach ( $metadata['streams'] as $stream ) {
			$streamTypes[] = $stream['type'];
		}
		return array_unique( $streamTypes );
	}

	/**
	 * @param File $file
	 * @return int
	 */
	public function getOffset( $file ) {
		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( !$metadata || isset( $metadata['error'] ) || !isset( $metadata['offset'] ) ) {
			return 0;
		}
		return $metadata['offset'];
	}

	/**
	 * @param File $file
	 * @return int
	 */
	public function getLength( $file ) {
		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return 0;
		}
		return $metadata['length'];
	}

	/**
	 * Get useful response headers for GET/HEAD requests for a file with the given metadata
	 * @param array $metadata Contains this handler's unserialized getMetadata() for a file
	 * @return array
	 * @since 1.30
	 */
	public function getContentHeaders( $metadata ) {
		$result = [];
		$metadata = $this->unpackMetadata( $metadata, false );

		if ( $metadata && !isset( $metadata['error'] ) && isset( $metadata['length'] ) ) {
			$result = [ 'X-Content-Duration' => (float)$metadata['length'] ];
		}

		return $result;
	}

	/**
	 * @param File $file
	 * @return float|int
	 */
	public function getFramerate( $file ) {
		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return 0;
		}

		// Return the first found theora stream framerate:
		foreach ( $metadata['streams'] as $stream ) {
			if ( $stream['type'] === 'Theora' ) {
				return $stream['header']['FRN'] / $stream['header']['FRD'];
			}
		}
		return 0;
	}

	/**
	 * @param File $file
	 * @return string
	 */
	public function getShortDesc( $file ) {
		global $wgLang, $wgMediaAudioTypes, $wgMediaVideoTypes;

		$streamTypes = $this->getStreamTypes( $file );
		if ( !$streamTypes ) {
			return parent::getShortDesc( $file );
		}
		if ( array_intersect( $streamTypes, $wgMediaVideoTypes ) ) {
			// Count multiplexed audio/video as video for short descriptions
			$msg = 'timedmedia-ogg-short-video';
		} elseif ( array_intersect( $streamTypes, $wgMediaAudioTypes ) ) {
			$msg = 'timedmedia-ogg-short-audio';
		} else {
			$msg = 'timedmedia-ogg-short-general';
		}
		return wfMessage( $msg, implode( '/', $streamTypes ),
			$wgLang->formatTimePeriod( $this->getLength( $file ) ) )->text();
	}

	/**
	 * @param File $file
	 * @return string
	 */
	public function getLongDesc( $file ) {
		global $wgLang, $wgMediaVideoTypes, $wgMediaAudioTypes;

		$streamTypes = $this->getStreamTypes( $file );
		if ( !$streamTypes ) {
			$unpacked = $this->unpackMetadata( $file->getMetadata() );
			if ( isset( $unpacked['error']['message'] ) ) {
				return wfMessage( 'timedmedia-ogg-long-error', $unpacked['error']['message'] )->text();
			}
			return wfMessage( 'timedmedia-ogg-long-no-streams' )->text();
		}
		if ( array_intersect( $streamTypes, $wgMediaVideoTypes ) ) {
			if ( array_intersect( $streamTypes, $wgMediaAudioTypes ) ) {
				$msg = 'timedmedia-ogg-long-multiplexed';
			} else {
				$msg = 'timedmedia-ogg-long-video';
			}
		} elseif ( array_intersect( $streamTypes, $wgMediaAudioTypes ) ) {
			$msg = 'timedmedia-ogg-long-audio';
		} else {
			$msg = 'timedmedia-ogg-long-general';
		}
		$size = 0;
		$unpacked = $this->unpackMetadata( $file->getMetadata() );
		if ( !$unpacked || isset( $unpacked['error'] ) ) {
			$length = 0;
		} else {
			$length = $this->getLength( $file );
			foreach ( $unpacked['streams'] as $stream ) {
				if ( isset( $stream['size'] ) ) {
					$size += $stream['size'];
				}
			}
		}
		return wfMessage(
			$msg,
			implode( '/', $streamTypes ),
			$wgLang->formatTimePeriod( $length ),
			$wgLang->formatBitrate( $this->getBitRate( $file ) )
		)->numParams(
			$file->getWidth(),
			$file->getHeight()
		)->text();
	}

	/**
	 * @param File $file
	 * @return float|int
	 */
	public function getBitRate( $file ) {
		$size = 0;
		$unpacked = $this->unpackMetadata( $file->getMetadata() );
		if ( !$unpacked || isset( $unpacked['error'] ) ) {
			$length = 0;
		} else {
			$length = $this->getLength( $file );
			if ( isset( $unpacked['streams'] ) ) {
				foreach ( $unpacked['streams'] as $stream ) {
					if ( isset( $stream['size'] ) ) {
						$size += $stream['size'];
					}
				}
			}
		}
		return $length ? $size / $length * 8 : 0;
	}
}
