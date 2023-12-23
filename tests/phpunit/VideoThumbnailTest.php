<?php
/**
 * @ingroup timedmedia
 * @author dale
 * @group medium
 * @group Database
 */
class VideoThumbnailTest extends ApiVideoUploadTestCase {

	/**
	 * Once video files are uploaded test thumbnail generating
	 *
	 * @dataProvider mediaFilesProvider
	 * @param array $file
	 * Broken as per bug 61877
	 * @group Broken
	 * @covers \MediaWiki\TimedMediaHandler\ApiQueryVideoInfo
	 */
	public function testApiThumbnails( $file ) {
		// Upload the file to the mediaWiki system
		$result = $this->uploadFile( $file );

		// Do a API request and check for valid thumbnails:
		$fileName = basename( $file['filePath'] );
		$params = [
			'action' => 'query',
			'titles' => 'File:' . $fileName,
			'prop' => 'imageinfo',
			'iiprop'	=> "url|size|thumbmime",
		];

		// Do a request for a small ( 200px ) thumbnail
		[ $result, , ] = $this->doApiRequest(
			array_merge( $params, [
					'iiurlwidth' => '200'
				]
			)
		);

		// Check The thumbnail output:
		$this->assertTrue( isset( $result['query'] ) );

		$page = current( $result['query']['pages'] );
		$this->assertTrue( isset( $page['imageinfo'] ) );

		$imageInfo = current( $page['imageinfo'] );

		// Make sure we got a 200 wide pixel image:
		$this->assertEquals( 200, (int)$imageInfo['thumbwidth'] );

		// Thumbnails should be image/jpeg:
		$this->assertEquals( 'image/jpeg', $imageInfo['thumbmime'] );

		// Make sure the thumbnail url is valid and the correct size
		// ( assuming php has getimagesize function )
		if ( function_exists( 'getimagesize' ) ) {
			[ $width, , , ] = getimagesize( $imageInfo['thumburl'] );
			$this->assertEquals( 200, $width );
		}

		/**
		 * We combine tests because fixtures don't play well with dataProvider
		 * see README for more info
		 */

		// Test a larger thumbnail with 1 second time offset
		[ $result, , ] = $this->doApiRequest(
			array_merge( $params, [
				'iiurlwidth' => '600',
				'iiurlparam' => '1'
			] )
		);
		$page = current( $result['query']['pages'] );
		$imageInfo = current( $page['imageinfo'] );
		// Thumb should max out at source size ( no upscale )
		$targetWidth = ( (int)$file['width'] < 600 ) ? (int)$file['width'] : 600;
		$this->assertEquals( $targetWidth, (int)$imageInfo['thumbwidth'] );
		if ( function_exists( 'getimagesize' ) ) {
			[ $srcImageWidth, , , ] = getimagesize( $imageInfo['thumburl'] );
			$this->assertEquals( $targetWidth, $srcImageWidth );
		}
	}
}
