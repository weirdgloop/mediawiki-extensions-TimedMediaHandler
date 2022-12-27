<?php

namespace MediaWiki\TimedMediaHandler;

use MediaWiki\Config\Config;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileUndeleteCompleteHook;
use MediaWiki\Hook\FileUploadHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserTestGlobalsHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\ImagePageAfterImageLinksHook;
use MediaWiki\Page\ImagePage;
use MediaWiki\Page\WikiFilePage;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Status\Status;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

/**
 * Hooks for TimedMediaHandler extension
 *
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

	private readonly TranscodableChecker $transcodableChecker;

	public function __construct(
		private readonly Config $config,
		private readonly LinkRenderer $linkRenderer,
		private readonly RepoGroup $repoGroup,
		private readonly SpecialPageFactory $specialPageFactory,
	) {
		$this->transcodableChecker = new TranscodableChecker(
			$config,
			$repoGroup
		);
	}

	/**
	 * @param Article $imagePage
	 * @param string &$html
	 */
	public function onImagePageAfterImageLinks( $imagePage, &$html ): void {
		// load the file:
		$file = $this->repoGroup->findFile( $imagePage->getTitle(), [ 'ignoreRedirect' => true ] );
		if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
			$transcodeStatusTable = new TranscodeStatusTable(
				$imagePage->getContext(),
				$this->linkRenderer
			);
			$html .= $transcodeStatusTable->getHTML( $file );
		}
	}

	/**
	 * @param File $file LocalFile object
	 * @param bool $reupload
	 * @param bool $hasDescription
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ): void {
		// Check that the file is a transcodable asset:
		if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
			// Remove all the transcode files and db states for this asset
			WebVideoTranscode::removeTranscodes( $file );
			WebVideoTranscode::startJobQueue( $file );
		}
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
	 */
	public function onTitleMove( Title $title, Title $newTitle, User $user, $reason, Status &$status ): void {
		if ( $this->transcodableChecker->isTranscodableTitle( $title ) ) {
			// Remove all the transcode files and db states for this asset
			// Will be re-added after the file has moved
			$file = $this->repoGroup->findFile( $title, [ 'ignoreRedirect' => true ] );
			WebVideoTranscode::removeTranscodes( $file );
		}
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
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
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
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ): void {
		if ( !$oldimage && $this->transcodableChecker->isTranscodableFile( $file ) ) {
			WebVideoTranscode::removeTranscodes( $file );
		}
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
	}

	/**
	 * When a user asks for a purge, perhaps through our handy "update transcode status"
	 * link, make sure we've got the updated set of transcodes. This'll allow a user or
	 * automated process to see their status and reset them.
	 *
	 * @param WikiPage $wikiPage
	 */
	public function onArticlePurge( $wikiPage ): void {
		if ( $wikiPage->getTitle()->getNamespace() === NS_FILE ) {
			$file = $this->repoGroup->findFile( $wikiPage->getTitle(), [ 'ignoreRedirect' => true ] );
			if ( $this->transcodableChecker->isTranscodableFile( $file ) ) {
				WebVideoTranscode::cleanupTranscodes( $file );
			}
		}
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
