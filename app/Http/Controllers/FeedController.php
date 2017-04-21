<?php

namespace App\Http\Controllers;

use DB;
use App\Article;
use App\Feed;
use ArandiLopez\Feed\Factories\FeedFactory; //use SimplePie to parse RSS feeds, see: https://github.com/arandilopez/laravel-feed-parser
use Illuminate\Http\Request;

class FeedController extends Controller
{
   protected $feedFactory;

	public function index()
	{
		$feeds = DB::table('feeds')->join('categories', 'feeds.category_id', '=', 'categories.id')->orderBy('feed_name', 'asc')->select('feeds.id', 'feeds.category_id', 'feeds.feed_name', 'feeds.feed_desc', 'feeds.url', 'feeds.favicon', 'categories.name as category_name')->get();
		return response()->json($feeds);
	}

	public function updateall()
	{
		//first clean-up database, see cleanup function
		$this->cleanup();

		//get only 15 feeds at a time
		$feeds = Feed::orderBy('updated_at', 'asc')->take(15)->get();

		if (! empty($feeds)) {
			foreach ($feeds as $feed) {
				//update feed, see update function
				$this->update($feed);
			}
		}
	}

	public function update(Feed $Feed)
	{
		//set previous week
		$previousweek = date('Y-m-j H:i:s', strtotime('-7 days'));

		echo $Feed->url.'<br>';
		$feedFactory = new FeedFactory(['cache.enabled' => false]);
		$feeder = $feedFactory->make($Feed->url);
		$simplePieInstance = $feeder->getRawFeederObject();

		//only add articles and update feed when results are found
		if (!empty($simplePieInstance)) {

			foreach ($simplePieInstance->get_items() as $item) {
				//count the number of items that already exist in the database with the item url and feed_id
				$results_url = Article::where(['feed_id' => $Feed->id, 'url' => $item->get_permalink()])->count();
				$results_title = Article::where(['feed_id' => $Feed->id, 'subject' => $item->get_title()])->count();
				$date = $item->get_date('Y-m-j H:i:s');

				//add new article if no results are found and article date is no older than one week
				if ($results_url == 0 && $results_title == 0 && ! (strtotime($date) < strtotime($previousweek))) {
					$article = new Article;

					//get article content
					$article->feed_id = $Feed->id;
					$article->status = 'unread';
					$article->url = $item->get_permalink();
					$article->subject = $item->get_title();
					$article->content = $item->get_description();
					$article->published = $item->get_date('Y-m-j H:i:s');

					//get URL of first image
					//TODO: replace with SimplePie str_get_html function, see: http://stackoverflow.com/questions/9865130/getting-image-url-from-rss-feed-using-simplepie
					$description =  $item->get_description();
					preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $description, $image);
					if (array_key_exists('src', $image)) {
						$article->image_url = $image['src'];
					}

					//save article content to database
					$article->save();

					echo '- '.$item->get_title().'<br>';
				}
			}

			//update feed updated_at record
			Feed::where('id', $Feed->id)->update(['updated_at' => date('Y-m-j H:i:s')]);
			Feed::where('id', $Feed->id)->update(['feed_desc' => $simplePieInstance->get_description()]);
			Feed::where('id', $Feed->id)->update(['favicon' => $simplePieInstance->get_image_url()]);
		}
	}

	public function newrssfeed(Request $request)
	{
		//check if url is set in POST argument, else exit
		if ($request->has('url')) {

			//check if url is valid
			if (filter_var($request->input('url'), FILTER_VALIDATE_URL) === false) {
				echo '<br>Error: Entered value is not a valid url!';
				exit();
			}

			$feedFactory = new FeedFactory(['cache.enabled' => false]);
			$feeder = $feedFactory->make($request->input('url'));
			$simplePieInstance = $feeder->getRawFeederObject();

			if (!empty($simplePieInstance)) {
				echo $simplePieInstance->get_title().'<br>';
				echo $simplePieInstance->get_description().'<br>';
				echo $simplePieInstance->get_permalink().'<br>';
				//favicon has been deprecated: $simplePieInstance->get_favicon();

				$result = Feed::where('url', $simplePieInstance->get_permalink())->first();

				if (!empty($result)) {
					echo '<br>Feed already exists!';
				} elseif (empty($simplePieInstance->get_title())) {
					echo '<br>Error: feed_name is empty!';
				} else {
					$feed = new Feed;
					$feed->category_id = '1';
					$feed->feed_name = $simplePieInstance->get_title();
					$feed->feed_desc = $simplePieInstance->get_description();
					$feed->url = $simplePieInstance->get_permalink();
					$feed->favicon = $simplePieInstance->get_image_url();
					$feed->save();
					echo '<br>Feed added to the database!';
				}
			}
		}
	}

	public function cleanup()
	{
		//The starred items, unread and latest 10000 items remain in the database
		$ArticlesLatest = Article::where('status', 'read')->where('star_ind', '0')->orderBy('created_at', 'desc')->select('id')->take(10000)->get();
		$ArticlesStar = Article::where('star_ind', '1')->select('id')->get();
		$ArticlesUnread = Article::where('status', 'unread')->select('id')->get();

		//create new empty array to store id's
		$cleanup_item_ids = [];

		//store id's from ArticlesStar in cleanup_item_ids
		if (!empty($ArticlesStar)) {
			foreach ($ArticlesStar as $Article) {
				array_push($cleanup_item_ids, $Article->id);
			}
		}

		//store id's from ArticlesLatest in cleanup_item_ids
		if (!empty($ArticlesLatest)) {
			foreach ($ArticlesLatest as $Article) {
				array_push($cleanup_item_ids, $Article->id);
			}
		}

		//store id's from ArticlesUnread in cleanup_item_ids
		if (!empty($ArticlesUnread)) {
			foreach ($ArticlesUnread as $Article) {
				array_push($cleanup_item_ids, $Article->id);
			}
		}

		//delete items that are not in cleanup_item_ids array
		Article::whereNotIn('id', $cleanup_item_ids)->delete();
	}

	public function getFeed($id)
	{
		$Feed = Feed::find($id);
		if (!empty($Feed)) {
			$Feed['total_count'] = Article::where('feed_id', $id)->count();
			$Feed['unread_count'] = Article::where('feed_id', $id)->where('status', 'unread')->count();
			$Feed['articles'] = Feed::find($id)->articles;
		}

		return response()->json($Feed);
	}

	public function changecategory(Request $request)
	{
		if ($request->has('feed_id') && $request->has('category_id')) {
			//update feed with new category_id
			Feed::where('id', $request->input('feed_id'))->update(['category_id' => $request->input('category_id')]);
         return response()->json('done');
		}
	}

	public function changeall(Request $request)
	{
		if ($request->has('feeds')) {
			foreach ($request->input('feeds') as $feed) {
				if (isset($feed['delete'])) {
					$Feed = Feed::find($feed['feed_id']);
					Article::where('feed_id', $feed['feed_id'])->delete();
					Feed::where('id', $feed['feed_id'])->delete();
				} else {
					Feed::where('id', $feed['feed_id'])->update(['feed_name' => $feed['feed_name'],'category_id' => $feed['category_id']]);
				}
			}
			return response()->json('done');
		}
	}

	public function createFeed(Request $request)
	{
		$Feed = Feed::create($request->all());
		return response()->json($Feed);
	}

	public function deleteFeed($id)
	{
		$Feed = Feed::find($id);
		Article::where('feed_id', $id)->delete();
		Feed::where('id', $id)->delete();
		return response()->json('deleted');
	}

	public function updateFeed($id)
	{
		$Feed = Feed::find($id);
		$Feed->name = $request->input('name');
		$Feed->save();
		return response()->json($Feed);
	}
}
