<?php
$home = getenv("HOME");

define( "AUTO_PICTURE_DIR", $home."/redditautowallpaper");

$subreddits = array(
        "spaceporn",
        "waterporn",
        "earthporn",
        "wallpapers",
        "natureporn",
        "seaporn",
        "villageporn",
        "fireporn",
        "FuturePorn",
        "cityporn",
        "skylineporn" ,
        "BridgePorn",
        "ImaginaryBattlefields",
        "ImaginaryCityscapes",
        "ImaginaryWastelands",
        "IncredibleIndia",
        "InfraredPorn",
        "ITookAPicture",
        "IWishIWasThere",
        "MattePainting",
        "RoadPorn",
        "SkylinePorn",
        "Skyscrapers",
        "SpecArt",
        "StreetViewExplorers",
        "Wallpapers",
        "WorldCities",
        "ViewPorn",
        ); 

$redditWallpaper = new redditWallpaper($subreddits); 

class redditWallpaper { 
    public $subreddit = 'http://www.reddit.com/r/wallpaper'; 
    private $images = [];
    private $saveto = '/tmp';
    private $fallback_image = '';

    function __construct($subreddits=array(), $saveto=''){ 

        $this->saveto = AUTO_PICTURE_DIR."/wallpaper."; 
        $this->setupFolder(); 
        $this->decideSetMethod(); 

        if(!empty($saveto)){ 
            $this->saveto = $saveto; 
        }
	$this->subreddits = $subreddits; 
        $this->selectSubReddit($this->subreddits); 
        $image = $this->fetchWallpaper();       
        $this->setWallpaper($image);
    }

    private function setupFolder(){ 
        if(!file_exists(AUTO_PICTURE_DIR)){ 
            mkdir(AUTO_PICTURE_DIR);
        }
    }

    function selectSubReddit($subreddits){ 
        shuffle($subreddits);
        $this->subreddit = 'http://www.reddit.com/r/'.$subreddits[rand(0,count($subreddits)-1)];
        return $this->subreddit; 
    }
    
    function setWallpaper($paper) { 
        $paper = escapeshellcmd($paper);
        $cmd = str_replace("::FILE::", $paper, SET_BG_COMMAND ); 
        //echo "cmd: $cmd \n"; 
        exec($cmd);
    }

    function extractImages($html){ 
        preg_match_all("/http:\/\/imgur.com\/.[^\"]+/mi", $html, $full);       
        $_images = array_keys(array_flip($full[0]));  
	$images = array();		
        foreach($_images as $i=>$img){ 
                if(!preg_match_all("/gallery/", $img) && !preg_match_all("/new$/", $img)){ 
                        $images[$i] = str_replace("http://imgur.com", "http://i.imgur.com", $img).".jpg"; 
                }
        }
        return $images;

    }

    function getImages(){ 
        
	$this->images=array();
        
        $html = file_get_contents($this->subreddit); 
        $this->images = $this->extractImages($html); 
        if(empty($this->images)){ 
                $this->subreddits =  array_diff($this->subreddits, [$this->subreddit]);//remove offending subreddit and try again
                if(count($this->subreddits)){ 
                    $this->selectSubReddit($this->subreddits); 
                    $this->images = $this->getImages(); 
                }else{
                        die("Tried all subreddits. something else is badly wrong. ");
                }
        }
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
                //echo "checking image $url\n";
            $filename = basename($url); 

            if($this->checkAlreadySeen($filename)){ 
                $this->fallback_image =  AUTO_PICTURE_DIR."/".$filename; 
                return AUTO_PICTURE_DIR."/".$filename;
                //break; 
            }
    
            $ch = curl_init ($url);
            //curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_HEADER, true);
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
                    unset($type);
                    if(file_exists($filename)){ 
                        //echo "I chose $filename  at $url \n"; 
                        return $filename; 
                    }
                }else{
                    die("Cant write file: $this->tmpfile ");
                }
            }

        }
    }

    private function decideSetMethod(){ 

        $uname = `uname -a`; 
        if(preg_match("/darwin/i", $uname)){ 
            //osx mavericks...
            define( "SET_BG_COMMAND", 'osascript -e "tell application \"System Events\" to set picture of every desktop to \"::FILE::\""'); 
            return true; 
        } 

        $ps = `ps -ef`; 
        //xfce4 linux...
        if(preg_match("/xfce4/", $ps)){ 
            define( "SET_BG_COMMAND", "xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/image-path -n -t string -s ::FILE::"); 
            return true; 
        }


        //unknown / unsupported... try wmsetbg
        define( "SET_BG_COMMAND", "wmsetbg ::FILE::"); 
        return true; 

    }

}





