<?php
/**
 * PicoFotofolder. A Mansory/Lightbox plugin for galleries
 * Edited December 2019 by Maloja
 *
 * @author  Maloja
 * @license http://opensource.org/licenses/MIT The MIT License
 * @link    http://github.com/maloja/pico-fotofolder
 *
 */
class PicoFotofolder extends AbstractPicoPlugin {

    const API_VERSION = 2;
    protected $enabled = true;
    protected $dependsOn = array();

    /**
     * This private variables
     */
    private $p_keyword = 'fotofolder';
    private $p_count = 0;
    private $image_src = array();

    /**
     *
     * Triggered after Pico has prepared the raw file contents for parsing
     */
    public function onContentLoaded(&$rawContent) {
        $rawContent = preg_replace_callback( '/\(%\s+' . $this->p_keyword  .'\s*\(\s*(.*?)\s*\)\s+%\)/', function($match) {

            if ($match[1]) {

                //check for GD library see #1
                if (!(extension_loaded('gd')) | (!function_exists('gd_info'))) {
                    $out = 'Error: No GD support found. Make sure php-gd is installed.';
                }
                else {
                    list ($this->image_src['path'],
                          $this->image_src['sort'],
                          $this->image_src['order']) = explode(',', str_replace('"', '', $match[1]));

                    $this->image_src['path']  = trim($this->image_src['path']);
                    $this->image_src['sort']  = trim($this->image_src['sort']);
                    $this->image_src['order']  = trim($this->image_src['order']);
                    if ($this->image_src['sort'] == "") $this->image_src['sort'] = 'name';
                    if ($this->image_src['order'] == "") $this->image_src['order'] = 'dsc';

    				// handle image path if %assets_url% is used see #1
                    $this->image_src['path'] = preg_replace('/%assets_url%/', rtrim($this->getConfig('assets_url'), "/"), $this->image_src['path']);
                    $repl = '/http[s]?:\/\/' . $_SERVER['SERVER_NAME'] . '/';
                    $this->image_src['path'] = preg_replace($repl, '', $this->image_src['path']);

    				$img_metas = $this->readMetaArray();

    				if (count($img_metas) > 0) {
                        $out = $this->createOutput($img_metas);
                        $this->p_count++;
                    }
                    else {
                        $out = "no media found in: {$this->image_src['path']}";
                    }
                }
            }
            return $out;
        }, $rawContent);
    }


    /**
     * Triggered after Pico has rendered the page
     */
    public function onPageRendered(&$output ) {
        // add required elements in head tag
        if ($this->p_count > 0) {
            $jsh  = '    <!-- Fotofolder Elements -->' . "\n";
            $jsh .= '     <link href="' . $this->getConfig('plugins_url') . 'PicoFotofolder/assets/css/fotofolder.css" rel="stylesheet">' . "\n";
            $jsh .= '     <script src="' . $this->getConfig('plugins_url') . 'PicoFotofolder/vendor/lazyload/dist/lazyload.min.js"></script>' . "\n";
            $jsh .= '     <link href="' . $this->getConfig('plugins_url') . 'PicoFotofolder/vendor/baguettebox/dist/baguetteBox.css" rel="stylesheet">' . "\n";
            $jsh .= '     <script src="' . $this->getConfig('plugins_url') . 'PicoFotofolder/vendor/baguettebox/dist/baguetteBox.min.js"></script>' . "\n";
			$jsh .= '</head>' . "\n" . '<body>' . "\n";
            $output = preg_replace('/\\<\\/head\\>\s*\n\s*\\<body\\>/', $jsh, $output, 1);

            // Add LazyLoad
            $jsh  = '<script>' . "\n";
            $jsh .= '   var lazyLoadInstance = new LazyLoad({ ' . "\n";
            $jsh .= '       elements_selector: ".lazy", ' . "\n";
            $jsh .= '       load_delay: 500' . "\n";
            $jsh .= '   });' . "\n";
            $jsh .= '</script>' . "\n";
            $jsh .= '</body>' . "\n" . '</html>' . "\n";
            $output = preg_replace('/\\<\\/body\\>\s*\n\s*\\<\/html\\>/', $jsh, $output, 1);
        }
    }


    /***************************************************************
     * Private Functions
     */

	/***************************************************************/
	private function readMetaArray() {
        $dir = $_SERVER['DOCUMENT_ROOT'] . $this->image_src['path'];

        $img_metas = array();
        $pattern = '{,.}*.{[jJ][pP][gG],[jJ][pP][eE][gG],[pP][nN][gG],[gG][iI][fF],dat}';
        $filelist = glob($dir . '/' . $pattern, GLOB_BRACE);
		usort($filelist, create_function('$a,$b', 'return filemtime($b) - filemtime($a);'));

 		//check if metafile is still up to date or if we have to create a new one
		if (strpos($filelist[0], '.fotofolder.dat') == true) {
			$string_data = file_get_contents($filelist[0]);
			$img_metas = unserialize($string_data);
		}
		//ok recreate it
		else {
			foreach ($filelist as $img) {
            	if (strpos($img, '.fotofolder.dat') == false) {
        			list($width, $height, $type, $attr) = getimagesize($img);
        			$exif = (exif_read_data($img, 0, true));
        			$url = str_replace($_SERVER['DOCUMENT_ROOT'], '', $img);
        			$img_name = pathinfo($img, PATHINFO_BASENAME);

					// handle thumbnails
					if (!file_exists( $_SERVER['DOCUMENT_ROOT'] . $this->image_src['path'] . '/thumbnails' )) {
    					mkdir( $_SERVER['DOCUMENT_ROOT'] . $this->image_src['path'] . '/thumbnails', 0777, true);
					}
					$thumb_name = $_SERVER['DOCUMENT_ROOT'] . $this->image_src['path'] . '/thumbnails/thumb_' . $img_name;
					if ( !file_exists($thumb_name) ) {
        				$this->scaleImageCopy($img, $thumb_name, 300, 300);
					}
					elseif ( filemtime($thumb_name) < filemtime($img) ) {
        				$this->scaleImageCopy($img, $thumb_name, 300, 300);
					}
					if ($width > $height) $format = 'landscape';
					if ($width < $height) $format = 'portrait';
					if ( abs( 1 - $width / $height) > 0.8 ) $format = 'square';


					$thumb_date = filemtime($thumb_name);
					$thumb_url = $this->image_src['path'] . '/thumbnails/thumb_' . $img_name;

        			array_push( $img_metas, array(	'filename'   => $img,
			                     					'url'        => $url,
            			         					'imgname'    => $img_name,
			                     					'date'       => $exif['FILE']['FileDateTime'],
            			         					'width'      => $width,
                     								'height'     => $height,
                     								'type'       => $type,
                     								'attr'       => $attr,
                     								'format'     => $format,
                     								'thumb_url'  => $thumb_url,
												    'thumb_date' => $thumb_date ));

				}
			}
			$string_data = serialize($img_metas);
			file_put_contents($_SERVER['DOCUMENT_ROOT'] . $this->image_src['path'] . '/.fotofolder.dat', $string_data);
        }
        return($img_metas);
	}

	/***************************************************************/
    private function createOutput($img_metas) {

        if ( $image_src['order'] == 'asc') {
		    usort($img_metas, function($a, $b) {
                return $a[$this->image_src['sort']] <=> $b[$this->image_src['sort']];
            });
        }
        else {
		    usort($img_metas, function($a, $b) {
                return $b[$this->image_src['sort']] <=> $a[$this->image_src['sort']];
            });
        }

        $out = '<div class="mgrid baguette_' . $this->p_count . '">' . "\n";
        foreach ($img_metas as $pic) {
            $out .= "    <a href=\"{$pic['url']}\" class=\"mgrid-item {$pic['format']}\"> \n";
            $out .= "       <img class=\"lazy\" data-src=\"{$pic['thumb_url']}\" src=\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 11 14'%3E%3C/svg%3E\" alt=\" \">\n";
            $out .= "       <div class=\"zoomicon\" style=\"background-image: url('{$this->getConfig('plugins_url')}PicoFotofolder/assets/circleplus.png')\"> </div> \n";
            $out .= '    </a>' . "\n";
        }
        $out .= "</div>\n";

		$out .= "<script>\n";
		$out .= "	baguetteBox.run('.baguette_{$this->p_count}', { \n";
		$out .= "       fullScreen: true, \n";
		$out .= "   });\n";
		$out .= "</script>\n";
		return $out;
    }




    /**
    * Resize image - preserve ratio of width and height.
    * @param string $sourceImage path to source JPEG image
    * @param string $targetImage path to final JPEG image file
    * @param int $maxWidth maximum width of final image (value 0 - width is optional)
    * @param int $maxHeight maximum height of final image (value 0 - height is optional)
    * @param int $quality quality of final image (0-100)
    * @return bool
    */
    private function scaleImageCopy($sourceImage, $targetImage, $maxWidth, $maxHeight, $quality = 80) {
        // Obtain image from given source file.
        if (!$image = @imagecreatefromjpeg($sourceImage)) {
            return false;
        }

        // Get dimensions of source image.
        list($origWidth, $origHeight) = getimagesize($sourceImage);

        if ($maxWidth == 0) $maxWidth  = $origWidth;
        if ($maxHeight == 0) $maxHeight = $origHeight;

        // do not grow the image
        if ( ($origWidth < $maxWidth) && ($origHeight < $maxHeight) ) {
            $maxWidth  = $origWidth;
            $maxHeight = $origHeight;
        }

        // Calculate ratio of desired maximum sizes and original sizes.
        $widthRatio = $maxWidth / $origWidth;
        $heightRatio = $maxHeight / $origHeight;

        // Ratio used for calculating new image dimensions.
        $ratio = min($widthRatio, $heightRatio);

        // Calculate new image dimensions.
        $newWidth  = (int)$origWidth  * $ratio;
        $newHeight = (int)$origHeight * $ratio;

        // Create final image with new dimensions.
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagejpeg($newImage, $targetImage, $quality);

        // Free up the memory.
        imagedestroy($image);
        imagedestroy($newImage);

        return true;
    }
}
