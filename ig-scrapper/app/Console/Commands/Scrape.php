<?php

namespace App\Console\Commands;

use App\Posts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Scrape extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape {--file=userlist.txt : File name with list of users} {--proxy=null : Proxy url} {--proxy-login=null : Proxy login} {--proxy-password=null : Proxy password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start scraping Instagram data for specified users.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public $queryId;

    public $rhx_gis;

    public $scrape_dir;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->scrape_dir = storage_path() . "/scrape_files/";
        $filename = $this->option('file');
        if (!file_exists($filename)) {
            $this->error('File ' . $filename . " not found");
            die();
        }


        $file = file_get_contents($filename);
        $userlist = explode(PHP_EOL, $file);

        $this->info("Hello! All parameters are good. Let's start scraping...");

        foreach ($userlist as $key => $user) {
            $page = 1;
            $this->line("Start " . $user . "...");
            $hasPage = true;
            $pageParam = null;
            while ($hasPage) {
                $hasPage = false;
                $skipNext = false;
                try {
                    [$post_data, $profile_data] = $this->getInfoByUsername($user, $pageParam);
                }
                catch (\Exception $e) {
                    $this->error('Ooops, something went wrong - "' . $e->getMessage() . "\" in file " . $e->getFile() . " line " . $e->getLine());
                    $this->error('Error with get user. Continue...');
                    $post_data = [];
                }

                foreach ($post_data as $data) {
                    $node = $data->node;
                    if ($profile_data->edge_owner_to_timeline_media->count <= Posts::where("username", $user)->get()->count()) {
                        $skipNext = true;
                        break;
                    }

                    if (Posts::where("post_id", $node->id)->get()->count() > 0) {
                        continue;
                    }

                    try {
                        if (!File::exists($this->scrape_dir)) {
                            File::makeDirectory($this->scrape_dir);
                        }
                        if (!File::exists($this->scrape_dir . $node->id)) {
                            File::makeDirectory($this->scrape_dir . $node->id);
                        }

                        if ($node->__typename == "GraphSidecar") {
                            $slide_post_data = $this->getPostDataByShortcode($node->shortcode);

                            foreach ($slide_post_data as $slide_data) {
                                $slide_node = $slide_data->node;

                                if ($slide_node->is_video == "true" && !File::exists($this->scrape_dir . $node->id . "/" . $slide_node->shortcode . ".mp4"))
                                    File::copy($slide_node->video_url, $this->scrape_dir . $node->id . "/" . $slide_node->shortcode . ".mp4");
                                elseif (!File::exists($this->scrape_dir . $node->id . "/" . $slide_node->shortcode . ".jpg"))
                                    File::copy($slide_node->display_url, $this->scrape_dir . $node->id . "/" . $slide_node->shortcode . ".jpg");
                                sleep(rand(1, 10) / 10);
                            }
                        } else {
                            if ($node->is_video == "true" && !File::exists($this->scrape_dir . $node->id . "/" . $node->shortcode . ".mp4"))
                                File::copy($node->video_url, $this->scrape_dir . $node->id . "/" . $node->shortcode . ".mp4");
                            elseif (!File::exists($this->scrape_dir . $node->id . "/" . $node->shortcode . ".jpg"))
                                File::copy($node->display_url, $this->scrape_dir . $node->id . "/" . $node->shortcode . ".jpg");
                            sleep(rand(1, 10) / 10);
                        }

                    } catch (\Exception $e) {
                        $this->error('Ooops, something went wrong - "' . $e->getMessage() . "\" in file " . $e->getFile() . " line " . $e->getLine());
                        $this->error('Error with bad image. Continue...');
                    }


                    try {


                        $post = new Posts();
                        $post->post_id = $node->id;
                        $post->username = $node->owner->username;

                        if (!empty($node->edge_media_to_caption->edges)) {
                            foreach ($node->edge_media_to_caption->edges as $edge) {
                                $post->caption = $edge->node->text ?? $node->accessibility_caption;
                            }
                        } else $post->caption = $node->accessibility_caption;

                        $post->caption = $post->caption ?? "not specified";
                        $post->date = date("Y-m-d", $node->taken_at_timestamp);
                        $post->location = $node->location->name ?? "not specified";
                        $post->comment_count = $node->edge_media_to_comment->count;
                        $post->like_count = $node->edge_media_preview_like->count;
                        $post->save();
                    } catch (\Exception $e) {
                        $this->error('Ooops, something went wrong - "' . $e->getMessage() . "\" in file " . $e->getFile() . " line " . $e->getLine());
                        $this->error('Error with add post. Continue...');
                    }

                }

                try {
                    if ($profile_data->edge_owner_to_timeline_media->page_info->has_next_page == "true" && $skipNext !== true) {
                        $pageParam = ["query_hash" => $this->queryId,
                            "variables" => json_encode([
                                "id" => $profile_data->id ?? $post_data[0]->node->owner->id,
                                "first" => 12,
                                "after" => $profile_data->edge_owner_to_timeline_media->page_info->end_cursor
                            ])];

                        [$post_data, $profile_data] = $this->getInfoByUsername($user, $pageParam);
                        $hasPage = true;
                    } else $hasPage = false;
                }
                catch (\Exception $e) {
                    $this->error('Ooops, something went wrong - "' . $e->getMessage() . "\" in file " . $e->getFile() . " line " . $e->getLine());
                    $this->error('Error with get new page. Continue...');
                }

                $this->info("Page " . $page . " complete. Continue...");
                $page++;
            }

            $this->info("User " . $user . " complete. Continue...");

        }

        $this->info("Work complete! Good luck!");

    }

    public function getInfoByUsername($user, $pageParam = null)
    {
        if ($pageParam === null) {
            $opts = [
                "http" => [
                    "method" => "GET",
                    "header" => "Accept-language: en\r\n" .
                        "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13\r\n"
                ]
            ];
            if ($this->option('proxy') !== "null") {
                $opts["http"]["proxy"] = "tcp://" . trim($this->option('proxy'));
                $opts["http"]["request_fulluri"] = "true";

                if ($this->option('proxy-login') !== null && $this->option('proxy-password') !== null) {
                    $auth = base64_encode($this->option('proxy-login') . ':' . $this->option('proxy-password'));
                    $opts["http"]["header"] .= "Proxy-Authorization: Basic $auth";
                }
            }

            $context = stream_context_create($opts);
            $response = file_get_contents("https://www.instagram.com/" . $user . "/",
                false, $context);
            $this->getQueryId($response);
            preg_match('/_sharedData = ({.*);<\/script>/', $response, $matches);
            $post_data = json_decode($matches[1])->entry_data->ProfilePage[0]->graphql->user->edge_owner_to_timeline_media->edges;
            $profile_data = json_decode($matches[1])->entry_data->ProfilePage[0]->graphql->user;
            $this->rhx_gis = json_decode($matches[1])->rhx_gis;

            sleep(rand(1, 10) / 10);
            return [$post_data, $profile_data];
        } else {

            $opts = [
                "http" => [
                    "method" => "GET",
                    "header" => "Accept-language: en\r\n" .
                        "x-instagram-gis: " . md5($this->rhx_gis . ":" . $pageParam["variables"]) . "\r\n" .
                        "x-requested-with: XMLHttpRequest\r\n" .
                        "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13\r\n"
                ]
            ];

            if ($this->option('proxy') !== "null") {
                $opts["http"]["proxy"] = "tcp://" . trim($this->option('proxy'));
                $opts["http"]["request_fulluri"] = "true";

                if ($this->option('proxy-login') !== null && $this->option('proxy-password') !== null) {
                    $auth = base64_encode($this->option('proxy-login') . ':' . $this->option('proxy-password'));
                    $opts["http"]["header"] .= "Proxy-Authorization: Basic $auth";
                }
            }

            $context = stream_context_create($opts);
            $response = json_decode(file_get_contents("https://www.instagram.com/graphql/query/?" . http_build_query($pageParam),
                false, $context));

            $post_data = $response->data->user->edge_owner_to_timeline_media->edges;
            $profile_data = $response->data->user;

            sleep(rand(1, 10) / 10);
            return [$post_data, $profile_data];
        }

    }

    public function getQueryId($html)
    {
        preg_match('~href=\"/static/bundles/metro/ProfilePageContainer.js/(.*)\.js"~', $html, $matches);
        $file = file_get_contents("http://images" . floor(rand() * 33) . "-focus-opensocial.googleusercontent.com" . "/gadgets/proxy?container=none&url=https://www.instagram.com/static/bundles/metro/ProfilePageContainer.js/" . $matches[1] . ".js");
        preg_match_all('~queryId:\"(.{32})\"~', $file, $matches);

        $this->queryId = $matches[1][2]; // because it work
    }

    public function getPostDataByShortcode($shortcode)
    {
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Accept-language: en\r\n" .
                    "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13\r\n"
            ]
        ];
        if ($this->option('proxy') !== "null") {
            $opts["http"]["proxy"] = "tcp://" . trim($this->option('proxy'));
            $opts["http"]["request_fulluri"] = "true";

            if ($this->option('proxy-login') !== null && $this->option('proxy-password') !== null) {
                $auth = base64_encode($this->option('proxy-login') . ':' . $this->option('proxy-password'));
                $opts["http"]["header"] .= "Proxy-Authorization: Basic $auth";
            }
        }

        $context = stream_context_create($opts);
        $response = file_get_contents("https://www.instagram.com/p/" . $shortcode . "/",
            false, $context);
        preg_match('/_sharedData = ({.*);<\/script>/', $response, $matches);
        $post_data = json_decode($matches[1])->entry_data->PostPage[0]->graphql->shortcode_media->edge_sidecar_to_children->edges;
        sleep(rand(1, 10) / 10);
        return $post_data;
    }
}
