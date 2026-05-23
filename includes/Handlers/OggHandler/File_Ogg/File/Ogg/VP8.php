<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------------+
// | File_Ogg PEAR Package for Accessing Ogg Bitstreams                         |
// | Copyright (c) 2005-2007                                                    |
// | David Grant <david@grant.org.uk>                                           |
// | Tim Starling <tstarling@wikimedia.org>                                     |
// +----------------------------------------------------------------------------+
// | This library is free software; you can redistribute it and/or              |
// | modify it under the terms of the GNU Lesser General Public                 |
// | License as published by the Free Software Foundation; either               |
// | version 2.1 of the License, or (at your option) any later version.         |
// |                                                                            |
// | This library is distributed in the hope that it will be useful,            |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of             |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU          |
// | Lesser General Public License for more details.                            |
// |                                                                            |
// | You should have received a copy of the GNU Lesser General Public           |
// | License along with this library; if not, write to the Free Software        |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA |
// +----------------------------------------------------------------------------+

use MediaWiki\TimedMediaHandler\Handlers\OggHandler\OggException;

define( 'OGG_VP8_IDENTIFICATION_HEADER', 0x4F );
define( 'OGG_VP8_IDENTIFICATION_PAGE_OFFSET', 0 );
define( 'OGG_VP8_COMMENTS_PAGE_OFFSET', 1 );

/**
 * @author      David Grant <david@grant.org.uk>, Tim Starling <tstarling@wikimedia.org>
 * @category    File
 * @copyright   David Grant <david@grant.org.uk>, Tim Starling <tstarling@wikimedia.org>
 * @license     http://www.gnu.org/copyleft/lesser.html GNU LGPL
 * @link        http://pear.php.net/package/File_Ogg
 * @link        https://people.freedesktop.org/~slomo/ogg-vp8/ogg-vp8.pdf
 * @package     File_Ogg
 * @version     CVS: $Id: VP8.php,v 1.9 2005/11/16 20:43:27 djg Exp $
 */
class File_Ogg_VP8 extends File_Ogg_Media
{

    /**
     * Version of VP8 as retrieved from VP8 identifcation header
     *
     * @var     string
     * @access  private
     */
    var $_VP8Version;

    /**
     * VP8 identifcation header
     *
     * @var     array
     * @access  private
     */
    var $_idHeader;

    /**
     * Width of the encoded frame
     *
     * @var     int
     * @access  private
     */
    var $_frameWidth;

    /**
     * Height of the encoded frame
     *
     * @var     int
     * @access  private
     */
    var $_frameHeight;

    /**
     * Frame rate for the encoded video
     *
     * @var     double
     * @access  private
     */
    var $_frameRate;

    /**
     * Pixel Aspect Ratio
     *
     * @var     double
     * @access  private
     */
    var $_physicalAspectRatio;
    var $_kfgShift = 32;

   /**
     * @access  private
     */
    function __construct($streamSerial, $streamData, $filePointer)
    {
        parent::__construct($streamSerial, $streamData, $filePointer);
        $this->_decodeIdentificationHeader();
        $this->_decodeCommentsHeader();

        $endSec =  $this->getSecondsFromGranulePos( $this->_lastGranulePos );
        $startSec = $this->getSecondsFromGranulePos( $this->_firstGranulePos );

        if( $startSec > 1){
            $this->_streamLength = $endSec - $startSec;
            $this->_startOffset = $startSec;
        }else{
            $this->_streamLength = $endSec;
        }
        $this->_avgBitrate = $this->_streamLength ? ($this->_streamSize * 8) / $this->_streamLength : 0;
    }


	function getSecondsFromGranulePos($granulePos){
        return (float)$granulePos >> 32;
	}

    /**
     * Get the 6-byte identification string expected in the common header
     */
    function getIdentificationString()
    {
        return OGG_STREAM_CAPTURE_VP8;
    }

    /**
     * Parse the identification header in a VP8 stream.
     * @access  private
     * @throws OggException
     */
    function _decodeIdentificationHeader()
    {
        $this->_decodeCommonHeader(OGG_VP8_IDENTIFICATION_HEADER, OGG_VP8_IDENTIFICATION_PAGE_OFFSET);
        $h = File_Ogg::_readBigEndian( $this->_filePointer, [
            'HDRTYP' => 8,
            'VMAJ' => 8,
            'VMIN' => 8,
            'PICW' => 16, /* FW */
            'PICH' => 16, /* FH */
            'PARN' => 24,
            'PARD' => 24,
            'FRN'  => 32, /* FPSN */
            'FRD'  => 32, /* FPSD */
        ]);
        if ( !$h ) {
            throw new OggException("Stream is undecodable due to a truncated header.", OGG_ERROR_UNDECODABLE);
        }

        // VP8 header type (Stream Info)
        if ( $h['HDRTYP'] != 1 ) {
            throw new OggException("Stream is undecodable due to incorrect header type.", OGG_ERROR_UNDECODABLE);
        }

        // VP8 version
        if ( $h['VMAJ'] != 1 || $h['VMIN'] != 0 ) {
            throw new OggException("Stream is undecodable due to an invalid VP8 version.", OGG_ERROR_UNDECODABLE);
        }
        $this->_VP8Version = "{$h['VMAJ']}.{$h['VMIN']}";

        // Frame height/width
        if ( !$h['PICW'] || !$h['PICH'] ) {
            throw new OggException("Stream is undecodable because it has frame size of zero.", OGG_ERROR_UNDECODABLE);
        }
        $this->_frameWidth = $h['PICW'];
        $this->_frameHeight = $h['PICH'];

        // Frame rate
        $this->_frameRate = $h['FRN'] / $h['FRD'];
        // Physical aspect ratio
        if ( !$h['PARN'] || !$h['PARD'] ) {
            $this->_physicalAspectRatio = 1;
        } else {
            $this->_physicalAspectRatio = $h['PARN'] / $h['PARD'];
        }

        $this->_idHeader = $h;
    }

    /**
     * Get an associative array containing header information about the stream
     * @access  public
     * @return  array
     */
    function getHeader() {
        return $this->_idHeader;
    }

    /**
     * Get a short string describing the type of the stream
     * @return string
     */
    function getType() {
        return 'VP8';
    }

    /**
     * Decode the comments header
     * @access private
     */
    function _decodeCommentsHeader()
    {
        $this->_decodeCommonHeader(OGG_VP8_IDENTIFICATION_HEADER, OGG_VP8_COMMENTS_PAGE_OFFSET);
        $h = File_Ogg::_readBigEndian( $this->_filePointer, [
            'HDRTYP' => 8,
            ' ' => 8,
        ]);
        if ( !$h ) {
            throw new OggException("Stream is undecodable due to a truncated header.", OGG_ERROR_UNDECODABLE);
        }

        // VP8 header type (Comments)
        if ( $h['HDRTYP'] != 2 ) {
            throw new OggException("Stream is undecodable due to incorrect header type.2", OGG_ERROR_UNDECODABLE);
        }
        $this->_decodeBareCommentsHeader();
    }

}
?>
