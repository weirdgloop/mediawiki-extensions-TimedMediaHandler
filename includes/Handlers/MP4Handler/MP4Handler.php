<?php

namespace MediaWiki\TimedMediaHandler\Handlers\MP4Handler;

use MediaWiki\FileRepo\File\File;
use MediaWiki\TimedMediaHandler\Handlers\ID3Handler\ID3Handler;

/**
 * MP4 handler
 */
class MP4Handler extends ID3Handler {

	/**
	 * @param string $path
	 * @return array
	 */
	protected function getID3( $path ) {
		$id3 = parent::getID3( $path );
		// Unset some parts of id3 that are too detailed and matroska specific:
		unset( $id3['quicktime'] );
		return $id3;
	}

	/**
	 * Get the "media size"
	 * @param File $file
	 * @param string $path
	 * @param string|false $metadata
	 * @return array|false
	 */
	public function getImageSize( $file, $path, $metadata = false ) {
		// Just return the size of the first video stream
		if ( $metadata === false ) {
			$metadata = $file->getMetadata();
		}
		$metadata = $this->unpackMetadata( $metadata );
		if ( isset( $metadata['error'] ) ) {
			return false;
		}
		if ( isset( $metadata['video']['resolution_x'] ) && isset( $metadata['video']['resolution_y'] ) ) {
			return [
				$metadata['video']['resolution_x'],
				$metadata['video']['resolution_y']
			];
		}
		return [ false, false ];
	}

	/**
	 * @param File $image
	 * @return string
	 */
	public function getMetadataType( $image ) {
		return 'mp4';
	}

	/**
	 * @param File $file
	 * @return string
	 */
	public function getWebType( $file ) {
		if ( $this->isAudio( $file ) ) {
			return 'audio/mp4';
		} else {
			$videoCodec = $this->getCodecString( $file ) ?: 'avc1.42E01E';
			return "video/mp4; codecs=\"{$videoCodec}, mp4a.40.2\"";
		}
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
		if ( isset( $metadata['video']['codec'] ) ) {
			$videoCodec = $metadata['video']['codec'];
			if ( str_contains( $videoCodec, 'H.264' ) ) {
				$videoCodec = 'H.264';
			} elseif ( str_contains( $videoCodec, 'H.265' ) ) {
				$videoCodec = 'H.265';
			}
			$streamTypes[] = $videoCodec;
		}
		if ( isset( $metadata['audio']['codec'] ) ) {
			$audioCodec = $metadata['audio']['codec'];
			if ( str_contains( $audioCodec, 'AAC' ) ) {
				$audioCodec = 'AAC';
			}
			$streamTypes[] = $audioCodec;
		}

		return $streamTypes;
	}

	/**
	 * @param File $file
	 * @return string HTML
	 */
	public function getShortDesc( $file ) {
		$streamTypes = $this->getStreamTypes( $file );
		if ( !$streamTypes ) {
			return parent::getShortDesc( $file );
		}
		return wfMessage( 'timedmedia-mp4-short-video' )
			->params( implode( '/', $streamTypes ) )
			->timeperiodParams( $this->getLength( $file ) )
			->escaped();
	}

	/**
	 * @param File $file
	 * @return string HTML
	 */
	public function getLongDesc( $file ) {
		$streamTypes = $this->getStreamTypes( $file );
		if ( !$streamTypes ) {
			return parent::getLongDesc( $file );
		}
		return wfMessage( 'timedmedia-mp4-long-video' )
			->params( implode( '/', $streamTypes ) )
			->timeperiodParams( $this->getLength( $file ) )
			->bitrateParams( $this->getBitRate( $file ) )
			->numParams( $file->getWidth(), $file->getHeight() )
			->sizeParams( $file->getSize() )
			->escaped();
	}

}
