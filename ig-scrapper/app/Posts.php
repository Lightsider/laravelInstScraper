<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $post_id
 * @property string $username
 * @property string $caption
 * @property string $date
 * @property string $location
 * @property int $like_count
 * @property int $comment_count
 */
class Posts extends Model
{
    public $table = "posts";
    /**
     * @var array
     */
    protected $fillable = ['post_id', 'username','caption', 'date', 'location', 'like_count', 'comment_count'];

    public $timestamps = false;

}
