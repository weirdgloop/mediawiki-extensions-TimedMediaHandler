<?php

namespace MediaWiki\TimedMediaHandler;

use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;

class RegistrationCallback {
	/**
	 * Modify config via registration callback
	 */
	public static function register( array $extInfo, SettingsBuilder $settingsBuilder ): void {
		$tmhFileExtensions = $settingsBuilder->getConfig()->get( 'TmhFileExtensions' );
		$settingsBuilder->putConfigValue( MainConfigNames::FileExtensions, $tmhFileExtensions );

		// Transcode jobs must be explicitly requested from the job queue:
		$settingsBuilder->putConfigValue( MainConfigNames::JobTypesExcludedFromDefaultQueue, [ 'webVideoTranscode' ] );

		// validate enabled transcodeset values
		WebVideoTranscode::validateTranscodeConfiguration( $settingsBuilder->getConfig() );
	}
}
