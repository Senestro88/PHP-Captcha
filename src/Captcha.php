<?php
    namespace Senestro88\captcha;
    /**
     * PHP Captcha
     * 
     * (c) John Yusuf Habila <Senestro88@gmail.com>
     * 
     * For the full copyright and license information, please view the LICENSE
     * file that was distributed with this source code.
     */
    /**
     * 
     */
    class Captcha{
        private $paths = array("main"=>null, "data"=>null, "bg"=>null, "tmp"=>null);
        private $options = array(
            'bgColor'=> "#fff",
            'textColor'=> "#303832",
            'signColor'=> "#4278F5",
            'lineColor'=> "#47524a",
            'noiseColor'=> "#47524a",
            'fontRatio'=> 0.4,
            'codeLength'=> 6,
            'namespace'=> "default",
            'width'=> 200,
            'height'=> 80,
            'transparentPercent'=> 20,
            'caseSensitive'=> false,
            'numLines'=> 10,
            'noiseLevel'=> 4,
            'expireTime'=> 900,
            'randomBackground'=> true,
            'randomSpaces'=> true,
            'textAngles'=> true,
            'randomBaseline'=> true,
            'imageOutput'=> "png", // Supported are : png, jpe, gif
            'signatureText'=> ""
        );
        private $image = null;
        private $bgColor = null;
        private $textColor = null;
        private $signColor = null;
        private $lineColor = null;
        private $noiseColor = null;
        private $alphaValue = null;
        private $generatedCode = null;
        private $displayedCode = null;
        private $charset = 'abcdefghijkmnopqrstuvwxzyABCDEFGHJKLMNPQRSTUVWXZY0123456789';
        private $codeEntered = '';
        private $correctCode = false;
        private $ttSolved = 0; // Time to solve
        private $bgImage = "";
        private $wordFont = null;
        private $signFont = null;

        /* Create a new securimage object, pass options to set in the constructor. */
        function __construct(array $options = array()){
           if (function_exists('mb_internal_encoding')){mb_internal_encoding('UTF-8');}
            $this->paths['main'] = str_replace("\\", "/", dirname(__FILE__));
            $this->paths['data'] = $this->paths['main'].'/data/sessions/';
            $this->paths['bg'] =  str_replace("\\", "/", dirname(__FILE__))."/backgrounds/";
            $this->paths['tmp'] =  str_replace("\\", "/", dirname(__FILE__))."/temp/";
            if(!is_dir($this->paths['data'])){@mkdir($this->paths['data'], 0777, true);}
            if(!is_dir($this->paths['bg'])){@mkdir($this->paths['bg'], 0777, true);}
            if(!is_dir($this->paths['tmp'])){@mkdir($this->paths['tmp'], 0777, true);}
            self::filterOptions($options);
        }
        public function setOptions(array $options = array()){self::filterOptions($options);}
        public function namespace(string $namespace){
            if (!empty($namespace)) {
                $namespace = preg_replace('/[^a-z0-9-_]/i', '', $namespace);
                $namespace = substr($namespace, 0, 64);
                if (!empty($namespace)) {$this->options['namespace'] = $namespace;}
                else {$this->options['namespace'] = 'default';}
            }
        }
        public function createImage(){
            set_error_handler(array(&$this, 'errorHandler'));
            if(function_exists('imagecreatetruecolor')) {$imageCreate = 'imagecreatetruecolor';} else {$imageCreate = 'imagecreate';}
            $this->image = $imageCreate($this->options['width'], $this->options['height']);
            if (function_exists('imageantialias')) {imageantialias($this->image, true);}
            $this->allocateColors();
            $this->setBackground();
            $this->createCode();
            $this->drawNoise();
            $this->drawLines();
            $this->drawSignature();
            $this->drawWord();
        }
        public function displayCaptcha(){
            if ($this->headersSent() === false) {
                header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
                header("Cache-Control: no-store, no-cache, must-revalidate");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                switch ($this->options['imageOutput']) {
                    case 'png': header("Content-Type: image/png"); imagepng($this->image); break;
                    case 'jpg': header("Content-Type: image/jpeg"); imagejpeg($this->image, null, 90); break;
                    default: header("Content-Type: image/gif"); imagegif($this->image); break;
                }
            }else{echo 'Failed to generate captcha image, content has already been outputed. This is most likely due to misconfiguration or a PHP error was sent to the browser.';}
            imagedestroy($this->image);
            restore_error_handler();
            exit();
        }
        public function getCaptcha(){
            $name = $this->paths['tmp']."".md5(time()).".".($this->options['imageOutput'] == "png" ? "png" : ($this->options['imageOutput'] == "jpg" ? "jpg" : "gif"));
            switch ($this->options['imageOutput']) {
                case 'png': $image = imagepng($this->image, $name); break;
                case 'jpg': $image = imagejpeg($this->image, $name, 90); break;
                default: $image = imagegif($this->image, $name); break;
            }
            imagedestroy($this->image);
            try {
                clearstatcache();
                $imgContent = @file_get_contents($name);
                return "data:".($this->options['imageOutput'] == "png" ? 'image/png' : ($this->options['imageOutput'] == "jpg" ? 'image/jpeg' : 'image/gif')).";base64,".base64_encode($imgContent);
            } catch (\Exception $e) {} finally {clearstatcache(); @unlink($name);}
        }
        public function validateCaptcha(string $value){
            $this->codeEntered = $value;
            $this->validateCode();
            return $this->correctCode;
        }
        /* ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- */
        /* ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- */
        private function codeExpired(int $time){
                $expired = true;
                if (!is_numeric($this->options['expireTime']) || $this->options['expireTime'] < 1) {$expired = false;}
                else if ((time() - $time) < $this->options['expireTime']) {$expired = false;}
                return $expired;
            }
        private function getCode() : array {
                $cs = array();
                $name = $this->paths['data'].''.$this->options['namespace'].'.json';
                if(is_readable($name)){
                    $sessionContent = file_get_contents($name);
                    $decoded = json_decode($sessionContent, JSON_OBJECT_AS_ARRAY);
                    if(is_array($decoded) && isset($decoded['code']) && isset($decoded['display']) && isset($decoded['time'])){
                        if ($this->codeExpired($decoded['time']) === false){
                            $cs['code'] = $decoded['code'];
                            $cs['display'] = $decoded['display'];
                            $cs['time'] = $decoded['time'];
                        }
                    }
                }
                return $cs;
            }
        private function validateCode(){
                $this->namespace($this->options['namespace']);
                $getCode = $this->getCode(); // Always returning array
                $generatedCode = "";
                if(count($getCode) === 3){$generatedCode  = $getCode['code']; $this->ttSolved = (time() - $getCode['time']);}
                // Case sensitive was set from secureImage-show.php but not in class  the code saved in the session has capitals so set case sensitive to true
                if ($this->options['caseSensitive'] === false && preg_match('/[A-Z]/', $generatedCode)) {$this->options['caseSensitive'] = true;}
                $codeEntered = trim((($this->options['caseSensitive']) ? $this->codeEntered : strtolower($this->codeEntered)));
                $this->correctCode = false;
                if ($generatedCode !== "") {
                    if (strpos($generatedCode, ' ') !== false) {$codeEntered = strtolower(preg_replace('/\s+/', ' ', $codeEntered));}  // For multi word captchas, remove more than once space from input
                    if ((string) $generatedCode === (string) $codeEntered) {
                        $this->correctCode = true;
                        $name = $this->paths['data'].''.$this->options['namespace'].'.json';
                        if(file_exists($name)){@unlink($name);}
                    }
                }
            }
            private function headersSent(){
                if (headers_sent()) {return true;}
                else if (strlen((string)ob_get_contents()) > 0) { return true;}
                return false;
            }
            private function rand(){return (0.0001 * mt_rand(0, 9999));}
            private function substr($string, $start, $length = null){
                $substr= 'substr';
                if (function_exists('mb_substr')) {$substr = 'mb_substr';}
                if ($length === null) {return $substr($string, $start);}
                else {return $substr($string, $start, $length);}
            }
            private function strlen($string){
                $strlen= 'strlen';
                if (function_exists('mb_strlen')) {$strlen= 'mb_strlen';}
                return $strlen($string);
            }
            private function strpos($haystack, $needle, $offset = 0){
                $strpos = 'strpos';
                if (function_exists('mb_strpos')) {$strpos = 'mb_strpos';}
                return $strpos($haystack, $needle, $offset);
            }
            private function characterDimensions($char, $size, $angle, $font){
                $box = imagettfbbox($size, $angle, $font, $char);
                return array($box[2] - $box[0], max($box[1] - $box[7], $box[5] - $box[3]), $box[1]);
            }
            private function bboxDetails(float $size = 15, float $angle = 0, string $font = null, string $string = ""){
                $bbox = @imagettfbbox($size, $angle, $font, $string); $tmpBbox = array();
                if($bbox !== false){
                    $xCorr= 0 - $bbox[6]; // northwest X
                    $yCorr= 0 - $bbox[7]; // northwest Y
                    $tmpBbox['left']= $bbox[6] + $xCorr;
                    $tmpBbox['top']= $bbox[7] + $yCorr;
                    $tmpBbox['width']= $bbox[2] + $xCorr;
                    $tmpBbox['height']= $bbox[3] + $yCorr;
                }
               return $tmpBbox;
            }
            private function drawSignature(){
                if ($this->options['signatureText'] !== "" && is_readable($this->signFont)) {
                    $bboxDetails = $this->bboxDetails(15, 0, $this->signFont, $this->options['signatureText']);
                    if(isset($bboxDetails['width'])){
                        $x = (($this->options['width'] - $bboxDetails['width']) - 5);
                        $y = ($this->options['height'] - 5);
                        imagettftext($this->image, 15, 0, $x, $y, $this->signColor, $this->signFont, $this->options['signatureText']);
                    }
                }
            }
            private function drawLines(){
                if ($this->options['numLines'] > 0){
                    $width = $this->options['width'];
                    $height = $this->options['height'];
                    for ($line = 0; $line < $this->options['numLines']; ++ $line) {
                        $x = ($width * (1 + $line)) / ($this->options['numLines'] + 1);
                        $x += ((0.5 - self::rand()) * ($width / $this->options['numLines']));
                        $x = @round($x, 2);
                        $y = @mt_rand(($height * 0.1), ($height * 0.9));
                        $theta = @round(((self::rand() - 0.5) * M_PI) * 0.33, 2);
                        $len = @mt_rand(($width * 0.4), ($width * 0.7));
                        $lwid = !mt_rand(0, 2);
                        $k = @round((self::rand() * 0.6) + 0.2, 2);
                        $k = @round(($k * $k) * 0.5, 2);
                        $phi = @round(self::rand() * 6.28, 2);
                        $step = 0.5;
                        $dx = @round($step * cos($theta), 2);
                        $dy = @round($step * sin($theta), 2);
                        $n = ($len / $step);
                        $amp = @round((1.5 * self::rand()) / ($k + 5.0 / $len), 2);
                        $x0 = @round($x - 0.5 * $len * @cos($theta), 2);
                        $y0 = @round($y - 0.5 * $len * @sin($theta), 2);
                        $ldx = @round(- $dy * $lwid);
                        $ldy = @round($dx * $lwid);
                        for ($i = 0; $i < $n; ++ $i) {
                            $x = @round($x0 + $i * $dx + $amp * $dy * @sin($k * $i * $step + $phi), 2);
                            $y = @round($y0 + $i * $dy - $amp * $dx * @sin($k * $i * $step + $phi), 2);
                            @imagefilledrectangle($this->image, $x, $y, $x + $lwid, $y + $lwid, $this->lineColor);
                        }
                    }
                }
            }
            private function drawWord(){
                $ratio = ($this->options['fontRatio']) ? $this->options['fontRatio'] : 0.4;
                if ((float) $ratio < 0.1 || (float) $ratio >= 1) {$ratio = 0.4;}
                if (!is_readable($this->wordFont)) {imagestring($this->image, 4, 10, ($this->options['height'] / 2) - 5, 'Failed to load Font File', $this->textColor);}
                else{
                    $height = $this->options['height'];
                    $width = $this->options['width'];
                    $fontSize = ($this->options['height'] * $ratio);
                    $image = &$this->image;
                    $scale = 1;
                    $this->image = $image;
                    $captchaText = $this->displayedCode;
                    if ($this->options['randomSpaces'] === true && $this->strpos($captchaText, ' ') === false) {
                        if (mt_rand(1, 100) % 5 > 0) {
                            $index  = mt_rand(1, self::strlen($captchaText) -1);
                            $spaces = mt_rand(1, 3);
                            $captchaText = sprintf('%s%s%s',self::substr($captchaText, 0, $index), str_repeat(' ', $spaces), self::substr($captchaText, $index));
                        }
                    }
                    $fonts = array();  // List of fonts corresponding to each char $i
                    $angles = array();  // Angles corresponding to each char $i
                    $distance = array();  // Distance from current char $i to previous char
                    $dims = array();  // Dimensions of each individual char $i
                    $txtWid = 0; // Width of the entire text string, including spaces and distances
                    // Character positioning and angle
                    $angle0 = mt_rand(10, 20);
                    $angleN = round(mt_rand(-20, 10));
                    if($angle0 !== false && $angleN !== false){
                        if ($this->options['textAngles'] === false) {$angle0 = $angleN = $step = 0;}
                        if (mt_rand(0, 99) % 2 == 0) {$angle0 = -$angle0;}
                        if (mt_rand(0, 99) % 2 == 1) {$angleN = -$angleN;}

                        $step = (@abs($angle0 - $angleN) / (self::strlen($captchaText) - 1));
                        $step = ($angle0 > $angleN) ? -$step : $step;
                        $angle  = $angle0;

                        for ($index = 0; $index < $this->strlen($captchaText); ++$index) {
                            $font = $this->wordFont; // Select font
                            $fonts[]  = $font;
                            $angles[] = $angle;  // The angle of this character
                            $dist  = (round(mt_rand(-2, 0)) * $scale); // random distance between this and next character
                            $distance[] = $dist;
                            $char = self::substr($captchaText, $index, 1); // the character to draw for this sequence
                            $dim = self::characterDimensions($char, $fontSize, $angle, $font); // calculate dimensions of this character
                            $dim[0] += $dist;   // add the distance to the dimension (negative to bring them closer)
                            $txtWid += $dim[0]; // increment width based on character width
                            $dims[] = $dim;
                            $angle += $step; // Next angle
                            if ($angle > 20) { $angle = 20; $step  = ($step * -1);}
                            elseif ($angle < -20) { $angle = -20; $step  = (-1 * $step);}
                        }
                        $nextYPos = function($y, $i, $step) use ($height, $scale, $dims) {
                            static $dir = 1;
                            if ($y + $step + $dims[$i][2] + (10 * $scale) > $height) {$dir = 0;}
                            elseif ($y - $step - $dims[$i][2] < $dims[$i][1] + $dims[$i][2] + (5 * $scale)) {$dir = 1;}
                            if ($dir) {$y += $step;}
                            else {$y -= $step;}
                            return $y;
                        };

                        $cx = floor($width / 2 - ($txtWid / 2));
                        $x  = mt_rand(5 * $scale, max($cx * 2 - (5 * 1), 5 * $scale));

                        if ($this->options['randomBaseline'] === true) {$y = mt_rand($dims[0][1], $height - 10);}
                        else {$y = ($height / 2 + $dims[0][1] / 2 - $dims[0][2]);}

                        $randSclae = ($scale * mt_rand(5, 10));
                        for ($i = 0; $i < self::strlen($captchaText); ++$i) {
                            $font  = $fonts[$i];
                            $char  = self::substr($captchaText, $i, 1);
                            $angle = $angles[$i];
                            $dim   = $dims[$i];
                            if ($this->options['randomBaseline']) {$y = $nextYPos($y, $i, $randSclae);}
                            imagettftext($this->image, $fontSize, $angle, (int) $x, (int) $y, $this->textColor, $font, $char);
                            if ($i == ' ') {$x += $dim[0];} else {$x += ($dim[0] + $distance[$i]);}
                        }
                    }
                }
            }
            private function drawNoise(){
                if ($this->options['noiseLevel'] > 0){
                    if ($this->options['noiseLevel'] > 10) {$noiseLevel = 10;} else {$noiseLevel = $this->options['noiseLevel'];}
                    $noiseLevel *= M_LOG2E;
                    $imageWidth = $this->options['width'];
                    $imageHeight = $this->options['height'];
                    $image = $this->image;
                    for ($x = 1; $x < $imageWidth; $x += 10) {
                        for ($y = 1; $y < $imageHeight; $y += 10) {
                            for ($i = 0; $i < $noiseLevel; ++$i) {
                                $xOne = round(mt_rand($x, $x + 10));
                                $yOne = round(mt_rand($y, $y + 10));
                                $size = mt_rand(1, 3);
                                if ($xOne - $size <= 0 && $yOne - $size <= 0) continue; // Dont cover 0, 0
                                imagefilledarc($image, $xOne, $yOne, $size, $size, 0, mt_rand(180, 360), $this->noiseColor, IMG_ARC_PIE);
                            }
                        }
                    }
                }
            }
            private function saveCode(){
                $array = array('display' => $this->displayedCode, 'code' => $this->generatedCode, 'time' => time());
                $name = $this->paths['data'].''.$this->options['namespace'].'.json';
                @file_put_contents($name, json_encode($array, JSON_FORCE_OBJECT));
            }
            private function generateCode(){
                $code = '';  for($i = 1, $length = $this->strlen($this->charset); $i <= $this->options['codeLength']; ++$i) {$code .= $this->substr($this->charset, mt_rand(0, $length - 1), 1);}
                return $code;
            }
            private function createCode(){
                $this->generatedCode = $this->generateCode();
                $this->displayedCode = $this->generatedCode;
                $this->generatedCode = ($this->options['caseSensitive'] === true) ? $this->generatedCode : strtolower($this->generatedCode);
                $this->saveCode();
            }
            private function getBackground(){
                $images = array();
                if (($dh = opendir($this->paths['bg'])) !== false) {
                    while (($file = readdir($dh)) !== false) {if (preg_match('/(jpg|gif|png)$/i', $file)) $images[] = $file;}
                    closedir($dh);
                    if (count($images) > 0) {return rtrim($this->paths['bg'], '/') . '/' . $images[mt_rand(0, count($images)-1)];}
                }
                return false;
            }
            private function setBackground(){
                imagefilledrectangle($this->image, 0, 0, $this->options['width'], $this->options['height'], $this->bgColor);
                if ($this->options['randomBackground'] === true && $this->bgImage == '' && $this->paths['bg'] !== null && is_dir($this->paths['bg']) && is_readable($this->paths['bg'])) {
                    $this->paths['bg'] = realpath($this->paths['bg']);
                    $getImg = $this->getBackground();
                    if ($getImg !== false) {$this->bgImage = $getImg;}
                }
                if ($this->bgImage == '') {return;}
                $bgSize = @getimagesize($this->bgImage);
                if($bgSize === false) {return;}
                if(isset($bgSize[2])){
                    switch($bgSize[2]) {
                        case 1:  $bgImg = @imagecreatefromgif($this->bgImage); break;
                        case 2:  $bgImg = @imagecreatefromjpeg($this->bgImage); break;
                        case 3:  $bgImg = @imagecreatefrompng($this->bgImage); break;
                        default: return;
                    }
                }
                if(!$bgImg){return;}
                imagecopyresized($this->image, $bgImg, 0, 0, 0, 0, $this->options['width'], $this->options['height'], imagesx($bgImg), imagesy($bgImg));
            }
            private function allocateColors(){
                $this->alphaValue = intval($this->options['transparentPercent'] / 100 * 127);
                $bgColor = $this->options['bgColor'];
                $textColor = $this->options['textColor'];
                $signColor = $this->options['signColor'];
                $lineColor = $this->options['lineColor'];
                $noiseColor = $this->options['noiseColor'];
                $this->bgColor = imagecolorallocate($this->image, $bgColor['r'], $bgColor['g'], $bgColor['b']);
                $this->textColor = imagecolorallocatealpha($this->image, $textColor['r'], $textColor['g'], $textColor['b'], $this->alphaValue);
                $this->signColor = imagecolorallocatealpha($this->image, $signColor['r'], $signColor['g'], $signColor['b'], $this->alphaValue);
                $this->lineColor = imagecolorallocatealpha($this->image, $lineColor['r'], $lineColor['g'], $lineColor['b'], $this->alphaValue);
                $this->noiseColor = imagecolorallocatealpha($this->image, $noiseColor['r'], $noiseColor['g'], $noiseColor['b'], $this->alphaValue);
            }
        private function errorHandler($errno, $errstr, $errfile = '', $errline = 0, $errcontext = array()){
                $level = error_reporting();
                if ($level == 0 || ($level & $errno) == 0) {return true;}
                return false;
            }
        private function hex2rgb(string $hex){
            $r = $g = $b = 0;
            $hex = str_replace("#", "", $hex);
            if(strlen($hex) == 3 || strlen($hex) == 6){list($r, $g, $b) = array_map(function($c){return hexdec(str_pad($c, 2, $c));}, str_split(ltrim($hex, '#'), strlen($hex) > 4 ? 2 : 1));}
            return array("r"=>$r, "g"=>$g, "b"=>$b);
        }
        private function filterOptions(array $options = array()){
            $this->options = array_merge($this->options, $options);
            if (is_string($this->options['bgColor'])) {$this->options['bgColor'] = $this->hex2rgb($this->options['bgColor']);}
            if (is_string($this->options['lineColor'])) {$this->options['lineColor'] = $this->hex2rgb($this->options['lineColor']);}
            if (is_string($this->options['textColor'])) {$this->options['textColor'] = $this->hex2rgb($this->options['textColor']);}
            if (is_string($this->options['signColor'])) {$this->options['signColor'] = $this->hex2rgb($this->options['signColor']);}
            if (is_string($this->options['noiseColor'])) {$this->options['noiseColor'] = $this->hex2rgb($this->options['noiseColor']);}
            if ((int) $this->options['codeLength'] < 4) { $this->options['codeLength'] = 6;}
            if ((int) $this->options['transparentPercent'] < 1 || (int) $this->options['transparentPercent'] > 100) { $this->options['transparentPercent'] = 20;}
            if ((int) $this->options['numLines'] < 1) { $this->options['numLines'] = 6;}
            if ((int) $this->options['noiseLevel'] < 1) { $this->options['noiseLevel'] = 6;}
            if (!is_string($this->options['namespace'])) {$this->options['namespace'] = 'default';}
            if ($this->options['imageOutput'] !== "png" && $this->options['imageOutput'] !== "jpg" && $this->options['imageOutput'] !== "gif") {$this->options['imageOutput'] = 'png';}
            if(!is_string($this->options['signatureText'])){$this->options['signatureText'] = "";}
            // ------------------------------------------------------------------------------------------------------- //
            if (is_null($this->wordFont)) {$this->wordFont = $this->paths['main'] . '/data/word.ttf';}
            if (is_null($this->signFont)) {$this->signFont = $this->paths['main'] . '/data/signature.ttf';}
        }
    }