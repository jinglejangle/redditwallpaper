<?php
namespace rubbishninja\redditwallpaper; 

$multipleMonitors = true; 
$multiwall = true; 



$home = getenv("HOME");
define( "AUTO_PICTURE_DIR", $home."/redditautowallpaper");
$subreddits = array();
if(isset($argv[1])){ 
    if($argv[1] != 'keep'){ 
        $subreddits = explode(",", $argv[1]);
    }
    else { 
        redditWallpaper::keepCurrent(); 
        die();        
    }
}

if(!$multipleMonitors){ 
	$redditWallpaper = new redditWallpaper($subreddits, $multipleMonitors); 
	$redditWallpaper->doSetWallpapers(); 
}else{

	if(!$multiwall){ 
		$redditWallpaper = new redditWallpaper($subreddits, $multipleMonitors); 
		$redditWallpaper->doSetWallpapers(); 
	}else{
		$redditWallpaper = new multiwall($subreddits, $multipleMonitors); 
		$redditWallpaper->leftRight=false; 
		$redditWallpaper->doSetWallpapers(); 
	}
}


class redditWallpaper { 

    public $multipleMonitors=false; 
    public $subreddit = 'http://www.reddit.com/user/kjoneslol/m/sfwpornnetwork';  //helpful user collates all swfporn subreddits
    private $images = [];
    private $saveto = '/tmp';
    private $fallback_image = '';
    private $subredditsConfigFile = 'subreddits.txt';
    public $subreddits = array("wallpapers", "spaceporn", "waterporn", "skyporn", "earthporn", "fractalporn");  //default 
    private $_sub = null; 
    public $minWidth = 1440; //minimum X resolution
    public $maxSizeLimit = 190792;  //about 190MB max
    public $excludeSubreddits = array('comicbookporn', 'foodporn', 'warporn', 'militaryporn', 'quotesporn');  //ignore these ones, other peoples lunch, and too raunchy for work, whatever. 
    public $leftRight = false; 

    function __construct($subreddits=array() , $multipleMonitors=false){ 
        $this->saveto = AUTO_PICTURE_DIR."/wallpaper."; 
        $this->multipleMonitors = $multipleMonitors; 
        $this->setupFolder(); 
        $this->decideSetMethod(); 
        $this->checkDownloadFolderSize();
        if(!empty($subreddits)){ 
                $this->subreddits = $subreddits; 
        }else{
                $this->loadSubRedditsConfig();
        }
        $this->selectSubReddit($this->subreddits); 
    }


	public function doSetWallpapers(){ 
        $image = $this->fetchWallpaper();       
        $this->setWallpaper($image);
        if($this->multipleMonitors){
            $image2 = $this->fetchWallpaper();       
            $this->setWallpaper($image, $image2);

        }
	}
    private function loadSubRedditsConfig(){ 
        if(file_exists(dirname(__FILE__)."/".$this->subredditsConfigFile)) {
            $f = trim(file_get_contents(dirname(__FILE__)."/".$this->subredditsConfigFile)); 
            $subreddits = explode("\n", $f); 
            $this->subreddits = $subreddits; 
        }
    }

    private function setupFolder(){ 
        if(!file_exists(AUTO_PICTURE_DIR)){ 
            mkdir(AUTO_PICTURE_DIR);
        }
	}

	private function make_seed()
	{
		list($usec, $sec) = explode(' ', microtime());
		return (float) $sec + ((float) $usec * 100000);
	}

    function selectSubReddit($subreddits){ 
        shuffle($subreddits);
        $extra = array("hot", "new",  "" ); 
		srand($this->make_seed());
        $variant = $extra[rand(0,count($extra)-1)]; 
		srand($this->make_seed());
        $this->_sub = strtolower($subreddits[rand(0,count($subreddits)-1)])."/$variant";
        $this->subreddit = 'http://api.reddit.com/r/'.$this->_sub; 
        if(preg_match("/^user\//", $this->_sub)){ 
            //this is a users subreddit so no /r/ required
            $this->subreddit = 'http://api.reddit.com/'.$this->_sub; 
        }
		//echo "$this->subreddit\n"; 
        return $this->subreddit; 
    }

    function setWallpaper($paper, $paper2=null) { 
        $paper = escapeshellcmd($paper);
        $replace="::FILE::";
        $cmd = str_replace($replace, $paper, SET_BG_COMMAND ); 
        if(!is_null($paper2)){
            $replace="::FILE2::";
            $cmd = str_replace($replace, $paper2, $cmd ); 
			//echo $cmd; 
        }
		//echo "RUNNING:$cmd\n";
      	exec($cmd);
    }

    function getJson(){ 
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL,$this->subreddit);
        curl_setopt($ch,CURLOPT_USERAGENT,'https://github.com/jinglejangle/redditwallpaper');
        $json=curl_exec($ch);
        curl_close($ch);
        $obj = json_decode($json);
        $this->data = $obj; 
        return $obj;
    }

    function getImages(){ 
        $this->images=array();
        //find imgur...
        $images = array();
        $json = -1; 
        $json = $this->getJson(); 
        if(is_object($json)){ 
            foreach($json as $post){ 
                if(isset($post->children)){ 
                    if(count($post->children)){ 
                        foreach($post->children as $child){
                            if(isset($child->data->url)){	
                                if(in_array($child->data->domain , array('i.imgur.com')) && !in_array($child->data->subreddit, $this->excludeSubreddits )){ 
                                    $images[] = $child->data->url; 
                                    //echo "ADDED IMAGE ".$child->data->url."\n"; 
                                }
                                elseif (preg_match("/.jpg$/", $child->data->url)){ 
                                    //echo "ADDED IMAGE ".$child->data->url."\n"; 
                                    $images[] = $child->data->url;  //not imgur link but is a direct link to a jpg so we'll add it... 
                                }elseif (preg_match("/.png/i", $child->data->url)){ 
                                    //echo "ADDED IMAGE ".$child->data->url."\n"; 
                                    $images[] = $child->data->url;  //not imgur link but is a direct link to a jpg so we'll add it... 
                                }else{
									if(preg_match("/imgur.com\/[^a\/][a-zA-Z0-9]+/", $child->data->url)){ 
                                    	$images[] = $child->data->url.".jpg";  
									}else{
										//echo "SKIPPING ".print_r($child->data->url,1)."\n";
									}
								}
                            }

                        } 
                    }
                }
            }
        }else{
                die("Error. could not get json from api. Reddit down?"); 
        }
        $this->images = $images; 
        return $this->images; 
    }

    function fetchWallpaper(){ 
        $this->images = $this->getImages(); 
        if(empty($this->images)){ 
            die("Can't find any images in $this->subreddit ");
        }
        $image = false;
        $trycount=0; 
        while(!$image && $trycount < 20){  
            $image = $this->fetchImage($this->images) ; 
            if(!$image){  
                //if that failed, try another sub... 
                $this->selectSubReddit($this->subreddits);     
                $this->images = $this->getImages(); 
            }
            $trycount++; 
        }
        if($trycount>18){ 
            echo "I did a lot of tries, something was wrong with $this->subreddit image: $image. Using fallback image $this->fallback_image\n "; 
            return $this->fallback_image;  
        }
        return $image; 
    }

    function checkAlreadySeen($img){ 
        return file_exists(AUTO_PICTURE_DIR."/$img"); 
    }

    function fetchImage($images=array()){ 
        if(empty($images)){ 
            return false;
        }
        shuffle($images);
        foreach($images as $url){ 
            $filename = basename($url); 
            if($this->checkAlreadySeen($filename)){ 
                $this->fallback_image =  AUTO_PICTURE_DIR."/".$filename; 
                return AUTO_PICTURE_DIR."/".$filename;
            }

            $ch = curl_init ($url);
            curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER,1);
            $raw=curl_exec($ch);

            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($raw, 0, $header_size);

            $lines = explode("\n", $header);    
            foreach($lines as $line){ 
                if(preg_match("/^Content-Type: (.*)\/(.*)/", $line, $match)){ 
                    $type = ($match[2]);
                }
				if(preg_match("/^Location: (.*)\/(.*)/", $line, $match)){ 
                    $image_url = $match[1]."/".$match[2];
					if(!empty($image_url)){ 
						$this->images = array($image_url); 
						$this->fetchImage[$this->images]; 
					}
                }
            }

            if(!isset($type)){ 
                continue; 
            }else{ 

                if(trim($type) == 'jpeg'){ 
                    $type = 'jpg';  
                }
                $this->tmpfile = $this->saveto . $type; 
                
                $body = substr($raw, $header_size);
                curl_close ($ch);
                if(file_exists($this->tmpfile)){
                    unlink($this->tmpfile);
                }
                if(@$fp = fopen($this->tmpfile,'x')){ 
                    fwrite($fp, $body);
                    fclose($fp);
                    $filename = AUTO_PICTURE_DIR."/$filename"; 
                    rename($this->tmpfile, "$filename"); 

                    $image_size = getimagesize($filename); 
                    $width = $image_size[0];
                    $height = $image_size[1];

                    if($width < $this->minWidth){ 
                                return false; 
                    }
                    unset($type);
                    if(file_exists($filename)){ 
                        return $filename; 
                    }
                }else{
                    die("Cant write file: $this->tmpfile ");
                }
            }

        }
    }

    //todo break out into another class. 
    private function decideSetMethod(){ 

        $uname = `uname -a`; 

         //osx...
        if(preg_match("/darwin/i", $uname)){ 
			if(!$this->multipleMonitors){
				define( "SET_BG_COMMAND", 'osascript -e "tell application \"System Events\" to set picture of every desktop to \"::FILE::\""'); 
			}else{
				define( "SET_BG_COMMAND", 'osascript -e "tell application \"System Events\" to set picture of desktop 1 to \"::FILE::\"" && osascript -e "tell application \"System Events\" to set picture of desktop 2 to \"::FILE2::\"" '); 
			}
            return true; 
        } 

        $ps = `ps -ef`; 
        //xfce4 linux...
        if(preg_match("/xfce4/", $ps)){ 
            if(!$this->multipleMonitors){
                //define( "SET_BG_COMMAND", "xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/image-path -n -t string -s ::FILE::"); 
                define( "SET_BG_COMMAND", "xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/image-path -n -t string -s ::FILE:: && xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor1/image-path -n -t string -s ::FILE2:: "); 
            }else{
                //multiple monitor
                define( "SET_BG_COMMAND", "xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/image-path -n -t string -s ::FILE:: && xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor1/image-path -n -t string -s ::FILE2:: "); 
            }
            return true; 
        }
        if(preg_match("/gnome-shell/", $ps)){ 
            define( "SET_BG_COMMAND", "gsettings set org.gnome.desktop.background picture-uri  ::FILE:: "); 
            return true; 
        }

        //unknown / unsupported... try wmsetbg
        define( "SET_BG_COMMAND", "wmsetbg ::FILE::"); 
        return true; 

    }

    function checkDownloadFolderSize(){ 
        $this->imgDir = AUTO_PICTURE_DIR; 
        $ksize = `du -ksc $this->imgDir`;
        if($ksize >= $this->maxSizeLimit){ 
            $this->tidyDownloadFolder();
        }
    }

    function tidyDownloadFolder(){ 
        $cmd = "ls -t $this->imgDir/"; 
        $files = explode(PHP_EOL, trim(`$cmd`)); 
        for($x=count($files)-1;$x>5; $x--){ 
            unlink($this->imgDir."/".$files[$x]); 
        }
    }


    //work out the latest image and keep it 
    static function keepCurrent(){ 
        $imgDir = AUTO_PICTURE_DIR; 
        $cmd = "ls -t $imgDir/ | grep -v keep | tail -1"; 
        $file = trim(`$cmd`); 
        $newFile = $imgDir."/keep_".time().$file; 
        copy($imgDir."/".$file, $newFile); 
        echo("File:$file was saved as as $newFile ");
    }
}


class multiwall extends redditwallpaper { 
	public $leftRight = true; 
	function __construct($subreddits, $multi){ 
			$this->subreddits = $subreddits;
			$this->multi = $multi; 
	}

	function checkImageMagick(){ 
		return file_exists("/usr/local/bin/convert"); 
	}

	function doSetWallpapers(){ 
		if(!$this->checkImageMagick()){ 
			echo "Imagemagick not installed. Using multiple single images."; 
			parent::__construct($this->subreddits, $this->multi); 	
			parent::doSetWallpapers(); 
			return true; 
		}
		$this->subreddits = ['multiwall']; 
		parent::__construct($this->subreddits, true); 	

		$image= $this->fetchWallpaper();       
		$image = addslashes($image);
		$cmd = "/usr/local/bin/convert $image -crop 50%x100% +repage ".$image."_horizontal_%d.jpg"; 
		system($cmd);

		$image1 = $image."_horizontal_0.jpg"; 
		$image2 = $image."_horizontal_1.jpg"; 

		if($this->leftRight){ 
        	$this->setWallpaper($image1, $image2);
		}else{
        	$this->setWallpaper($image2, $image1);
		}
	}
}



