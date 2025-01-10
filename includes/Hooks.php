<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace MediaWiki\TimedMediaHandler;

use Article;
use DifferenceEngine;
use File;
use ImageHistoryList;
use ImagePage;
use LocalFile;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Diff\Hook\ArticleContentOnDiffHook;
use MediaWiki\Hook\CanonicalNamespacesHook;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileUndeleteCompleteHook;
use MediaWiki\Hook\FileUploadHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserTestGlobalsHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\ImageOpenShowImageInlineBeforeHook;
use MediaWiki\Page\Hook\ImagePageAfterImageLinksHook;
use MediaWiki\Page\Hook\ImagePageFileHistoryLineHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\Hook\WgQueryPagesHook;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Status\Status;
use MediaWiki\TimedMediaHandler\Handlers\TextHandler\TextHandler;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use RepoGroup;
use Skin;
use SkinTemplate;
use WikiFilePage;
use WikiPage;

/**
 * Hooks for TimedMediaHandler extension
 *
 * @file
 * @ingroup Extensions
 */
class Hooks implements
	ArticlePurgeHook,
	FileDeleteCompleteHook,
	FileUndeleteCompleteHook,
	FileUploadHook,
	ImagePageAfterImageLinksHook,
	PageMoveCompleteHook,
	ParserTestGlobalsHook,
	TitleMoveHook
{

	/** @var Config */
	private $config;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var SpecialPageFactory */
	private $specialPageFactory;

	/** @var TranscodableChecker */
	private $transcodableChecker;

	/**
	 * @param Config $config
	 * @param LinkRenderer $linkRenderer
	 * @param RepoGroup $repoGroup
	 * @param SpecialPageFactory $specialPageFactory
	 */
	public function __construct(
		Config $config,
		LinkRenderer $linkRenderer,
		RepoGroup $repoGroup,
		SpecialPageFactory $specialPageFactory
	) {
		$this->config = $config;
		$this->linkRenderer = $linkRenderer;
		$this->repoGroup = $repoGroup;
		$this->specialPageFactory = $specialPageFactory;
		$this->transcodableChecker = new TranscodableChecker(
			$config,
			$repoGroup
		);
	}

	/**
	 * Register remaining TimedMediaHandler hooks right after initial setup
	 *
	 * TODO: This function shouldn't need to exist.
	 *
	 * @return bool
	 */
	public static function register() {
		global $wgJobTypesExcludedFromDefaultQueue,
		$wgExcludeFromThumbnailPurge,
		$wgFileExtensions,
		$wgTmhFileExtensions;

		$wgFileExtensions = array_merge( $wgFileExtensions, $wgTmhFileExtensions );

		// Transcode jobs must be explicitly requested from the job queue:
		$wgJobTypesExcludedFromDefaultQueue[] = 'webVideoTranscode';

		// Exclude transcoded assets from normal thumbnail purging
		// ( a maintenance script could handle transcode asset purging)
		if ( isset( $wgExcludeFromThumbnailPurge ) ) {
			$wgExcludeFromThumbnailPurge = array_merge( $wgExcludeFromThumbnailPurge, $wgTmhFileExtensions );
			// Also add the .log file ( used in two pass encoding )
			// ( probably should move in-progress encodes out of web accessible directory )
			$wgExcludeFromThumbnailPurge[] = 'log';
		}

		// validate enabled transcodeset values
		WebVideoTranscode::validateTranscodeConfiguration();
		return true;
	}

	/**
	 * Wraps the isTranscodableFile function
	 * @param Title $title
	 * @return bool
	 */
	public static function isTranscodableTitle( $title ) {
		if ( $title->getNamespace() !== NS_FILE ) {
			return false;
		}
	}

	/**
	 * @param Article $imagePage
	 * @param string &$html
	 * @return bool
	 */
	public function onImagePageAfterImageLinks( $imagePage, &$html ) {
		// load the file:
		$file = $this->repoGroup->findFile( $imagePage->getTitle(), [ 'ignoreRedirect' => true ] );
		if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
			$transcodeStatusTable = new TranscodeStatusTable(
				$imagePage->getContext(),
				$this->linkRenderer
			);
			$html .= $transcodeStatusTable->getHTML( $file );
		}
		return true;
	}

	/**
	 * @param File $file LocalFile object
	 * @param bool $reupload
	 * @param bool $hasDescription
	 * @return bool
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ) {
		// Check that the file is a transcodable asset:
		if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
			// Remove all the transcode files and db states for this asset
			WebVideoTranscode::removeTranscodes( $file );
			WebVideoTranscode::startJobQueue( $file );
		}
		return true;
	}

	/**
	 * Handle moved titles
	 *
	 * For now we just remove all the derivatives for the oldTitle. In the future we could
	 * look at moving the files, but right now thumbs are not moved, so I don't want to be
	 * inconsistent.
	 * @param Title $title
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status &$status
	 * @return bool
	 */
	public function onTitleMove( Title $title, Title $newTitle, User $user, $reason, Status &$status ) {
		if ( $this->transcodableChecker->isTranscodableTitle( $title ) ) {
			// Remove all the transcode files and db states for this asset
			// Will be re-added after the file has moved
			$file = $this->repoGroup->findFile( $title, [ 'ignoreRedirect' => true ] );
			WebVideoTranscode::removeTranscodes( $file );
		}
		return true;
	}

	/**
	 * Hook to PageMoveComplete. Add transcode jobs for new file name
	 * @param LinkTarget $old Old title
	 * @param LinkTarget $new New title
	 * @param UserIdentity $user User who did the move
	 * @param int $pageid Database ID of the page that's been moved
	 * @param int $redirid Database ID of the created redirect
	 * @param string $reason Reason for the move
	 * @param RevisionRecord $revision RevisionRecord created by the move
	 * @return bool|void True or no return value to continue or false stop other hook handlers,
	 *     doesn't abort the move itself
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		if ( $this->transcodableChecker->isTranscodableTitle( $new ) ) {
			$newFile = $this->repoGroup->findFile( $new, [ 'ignoreRedirect' => true, 'latest' => true ] );
			WebVideoTranscode::startJobQueue( $newFile );
		}
	}

	/**
	 * Hook to FileDeleteComplete. Removes transcodes on delete.
	 * @param LocalFile $file
	 * @param string|null $oldimage
	 * @param WikiFilePage|null $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( !$oldimage && $this->transcodableChecker->isTranscodableFile( $file ) ) {
			WebVideoTranscode::removeTranscodes( $file );
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onFileUndeleteComplete( $title, $fileVersions, $user, $reason ) {
		$file = $this->repoGroup->findFile( $title, [ 'ignoreRedirect' => true, 'latest' => true ] );
		if ( $file && $this->transcodableChecker->isTranscodableFile( $file ) ) {
			WebVideoTranscode::removeTranscodes( $file );
			WebVideoTranscode::startJobQueue( $file );
		}
		return true;
	}

	/**
	 * When a user asks for a purge, perhaps through our handy "update transcode status"
	 * link, make sure we've got the updated set of transcodes. This'll allow a user or
	 * automated process to see their status and reset them.
	 *
	 * @param WikiPage $wikiPage
	 * @return bool
	 */
	public function onArticlePurge( $wikiPage ) {
		if ( $wikiPage->getTitle()->getNamespace() === NS_FILE ) {
			$file = $this->repoGroup->findFile( $wikiPage->getTitle(), [ 'ignoreRedirect' => true ] );
			if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
				WebVideoTranscode::cleanupTranscodes( $file );
			}
		}
		return true;
	}

	/**
	 * @param array &$globals
	 */
	public function onParserTestGlobals( &$globals ) {
		// reset player serial so that parser tests are not order-dependent
		TimedMediaTransformOutput::resetSerialForTest();

		$globals['wgEnableTranscode'] = false;
		$globals['wgFFmpegLocation'] = '/usr/bin/ffmpeg';
	}
}
