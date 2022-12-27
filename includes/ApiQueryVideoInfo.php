<?php
/**
 * Extends imageinfo with support for videoinfo sources property.
 *
 * Alternatively core ApiQueryImageInfo could support being extended in some straightforward ways.
 * see: https://www.mediawiki.org/wiki/User:Catrope/Extension_review/TimedMediaHandler#ApiQueryVideoInfo.php
 *
 */

namespace MediaWiki\TimedMediaHandler;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryImageInfo;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;

class ApiQueryVideoInfo extends ApiQueryImageInfo {

	/** @inheritDoc */
	public function __construct( ApiQuery $query, string $moduleName, ?string $prefix = null ) {
		// We allow a subclass to override the prefix, to create a related API module.
		// Some other parts of MediaWiki construct this with a null $prefix,
		// which used to be ignored when this only took two arguments
		parent::__construct( $query, $moduleName, $prefix ?? 'vi' );
	}

	/** @inheritDoc */
	public static function getInfo( $file, $prop, $result, $thumbParams = null, $opts = false ) {
		$vals = parent::getInfo( $file, $prop, $result, $thumbParams );
		if ( isset( $prop['derivatives'] ) ) {
			if ( $file->getHandler() && $file->getHandler() instanceof TimedMediaHandler ) {
				$vals['derivatives'] = WebVideoTranscode::getSources( $file, [ 'fullurl' ] );
				$result->setIndexedTagName( $vals['derivatives'], "derivative" );
			} else {
				// Non-TMH file, no derivatives.
				$vals['derivatives'] = [];
			}
		}
		return $vals;
	}

	/** @inheritDoc */
	public static function getPropertyMessages( $filter = [] ): array {
		$pm = parent::getPropertyMessages( $filter );
		$pm['derivatives'] = 'apihelp-query+videoinfo-paramvalue-prop-derivatives';
		return array_diff_key( $pm, array_flip( $filter ) );
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=query&titles=File:Folgers.ogv&prop=videoinfo&viprop=derivatives'
				=> 'apihelp-query+videoinfo-example-1',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls(): string {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Videoinfo';
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		$params = parent::getAllowedParams();
		foreach ( $params as $k => $v ) {
			// If PARAM_HELP_MSG is not manually set for this parameter, force fallback
			// to the query+imageinfo-param message (ie the parent module) rather than the
			// query+videoinfo-param that MediaWiki would default to.
			if ( !isset( $v[ApiBase::PARAM_HELP_MSG] ) ) {
				$params[$k][ApiBase::PARAM_HELP_MSG] = "apihelp-query+imageinfo-param-$k";
			}
		}

		return $params;
	}
}
