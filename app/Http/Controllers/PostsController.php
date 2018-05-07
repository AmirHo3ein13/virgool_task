<?php

namespace App\Http\Controllers;

use App\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\Exception;
use Nathanmac\Utilities\Parser\Parser;
use Psy\Exception\ErrorException;


class PostsController extends Controller
{
    /**
     * Get path of xml file and parses it to array
     *
     * @param $xml_path
     * @return array
     */
    private function parse_xml($xml_path){
        $parser = new Parser();
        return $parser->xml(File::get(public_path($xml_path)));
    }

    /**
     * Get an array that includes posts and extracts their body and title
     *
     * @param $data
     * @return array
     */
    private function get_title_body($data){
        $list = array();
        foreach ($data['channel']['item'] as $item) {
            array_push($list, [
                'id' => $item['wp:post_id'],
                'title' => $item['title'],
                'body' => $item['content:encoded'],
            ]);
        }
        return $list;
    }

    /**
     * Checks the status code of response
     *
     * @param $url
     * @return bool|string
     */
    private function get_http_response_code($url) {
        $headers = get_headers($url);
        return substr($headers[0], 9, 3);
    }

    /**
     * generate a random string
     *
     * @param int $length
     * @return string
     */
    private function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Get body of a post and saves it's images in local storage
     *
     * @param $str
     */
    private function save_images($str){
        $xml = simplexml_load_string('<tmp_tag>'.html_entity_decode($str).'</tmp_tag>');
        $images = $xml->xpath('//img');
        foreach ($images as $image){
            $url = $image->attributes()->src;
            if ($this->get_http_response_code($url) == "200"){
                $content = file_get_contents($url);
                Storage::put(
                    'posts/'.$this->generateRandomString(64).substr($url,strrpos($url, '.')), $content
                );
            }
        }
    }

    /**
     * Adds posts of exported file to database
     *
     * @param Request $request
     * @return string
     */
    public function add_posts(Request $request){

        $posts = $this->get_title_body($this->parse_xml($request->file('wordpress')->store('wordpress')));
        foreach ($posts as $post){
            if ($post['body'] != null){
                $this->save_images($post['body']);
            }
        }
        foreach ($posts as $post){
            Post::create([
                'title' => $post['title'],
                'body' => $this->reformat_body($post['body'], 'virgool-'),
                'id' => $post['id'],
            ]);
        }
        return json_encode(true);
    }

    /**
     * add class attribute to tags
     *
     * @param $xml
     * @param $value
     */
    private function add_class(&$xml, $value){
        foreach ($xml->children() as $child){
            if (isset($child->attributes()['class'])){
                $child->attributes()->class = ((string)$child->attributes()->class).$child->getName();
            }
            else{
                $child->addAttribute('class', $value.$child->getName());
            }
            $this->add_class($child, $value);
        }
    }

    /**
     * change the format of tags of posts' body
     * @param $body
     * @param $class_value
     * @return string
     */
    private function reformat_body($body, $class_value){
        $str = "<tmp_tag>".$body."</tmp_tag>";
        $xml = new \SimpleXMLElement(html_entity_decode($str));
        $this->add_class($xml,$class_value);
        $ret = str_replace(['<tmp_tag>','</tmp_tag>'],['',''],$xml->asXML());
        $ret = preg_replace('/<\?xml.*\?>/', '', $ret);
        return html_entity_decode($ret);
    }

    /**
     * return post
     * @param $id
     * @return string
     */
    public function index($id){
        return json_encode(Post::findOrFail($id));
    }

}