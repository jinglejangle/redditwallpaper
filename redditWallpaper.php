<?php
$home = getenv("HOME");
define( "AUTO_PICTURE_DIR", $home."/redditautowallpaper");

$redditWallpaper = new redditWallpaper(); 

class redditWallpaper { 
    public $subreddit = 'http://www.reddit.com/r/wallpaper'; 
    private $images = [];
    private $saveto = '/tmp';
    private $fallback_image = '';
    private $subredditsConfigFile = 'subreddits.txt';
    public $minWidth = 1440; //minimum X resolution

    function __construct($subreddits=array(), $saveto=''){ 

        $this->saveto = AUTO_PICTURE_DIR."/wallpaper."; 
        $this->setupFolder(); 
        $this->decideSetMethod(); 

        if(!empty($saveto)){ 
            $this->saveto = $saveto; 
        }
        if(!empty($subreddits)){ 
                $this->subreddits = $subreddits; 
        }else{
                $this->loadSubRedditsConfig();
        }
        $this->selectSubReddit($this->subreddits); 

        $image = $this->fetchWallpaper();       
        $this->setWallpaper($image);
    }

    private function loadSubRedditsConfig(){ 
        $f = trim(file_get_contents(dirname(__FILE__)."/".$this->subredditsConfigFile)); 
        $subreddits = explode("\n", $f); 
        $this->subreddits = $subreddits; 
    }

    private function setupFolder(){ 
        if(!file_exists(AUTO_PICTURE_DIR)){ 
            mkdir(AUTO_PICTURE_DIR);
        }
    }

    function selectSubReddit($subreddits){ 
        shuffle($subreddits);
        $extra = array("hot", "new", "" ); 
        $variant = $extra[rand(0,count($extra)-1)]; 
        $this->subreddit = 'http://api.reddit.com/r/'.strtolower($subreddits[rand(0,count($subreddits)-1)])."/$variant";
        return $this->subreddit; 
    }

    function setWallpaper($paper) { 
        $paper = escapeshellcmd($paper);
        $cmd = str_replace("::FILE::", $paper, SET_BG_COMMAND ); 
        //echo "cmd: $cmd \n"; 
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
        $json = $this->getJson(); 

        foreach($json as $post){ 
            if(isset($post->children)){ 
                if(count($post->children)){ 
                    foreach($post->children as $child){ 
                        if($child->data->domain == 'i.imgur.com'){ 
                            $images[] = $child->data->url; 
                        }
                    } 
                }
            }
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

                    $image_size = getimagesize($filename); 
                    $width = $image_size[0];
                    $height = $image_size[1];

                    //echo "WIDTH:$width HEIGHT:$height"; 
                    if($width < $this->minWidth){ 
                                return false; 
                    }
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





