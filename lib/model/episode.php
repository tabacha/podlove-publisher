<?php
namespace Podlove\Model;
use Podlove\Log;
use Podlove\ChaptersManager;

/**
 * We could use simple post_meta instead of a table here
 */
class Episode extends Base implements Licensable {

	public static function allByTime() {
		global $wpdb;

		$sql = 'SELECT * FROM `' . self::table_name() . '` e JOIN `' . $wpdb->prefix . 'posts` p ON e.post_id = p.ID ORDER BY p.post_date DESC';
		$rows = $wpdb->get_results($sql);

		if ( ! $rows ) {
			return array();
		}

		$episodes = array();
		foreach ( $rows as $row ) {
			$episode = new self();
			$episode->flag_as_not_new();
			foreach (self::property_names() as $property) {
				$episode->$property = $row->$property;
			}
			$episodes[] = $episode;
		}
		
		return $episodes;
	}

	/**
	 * Generate a human readable title.
	 * 
	 * Return name and, if available, the subtitle. Separated by a dash.
	 * 
	 * @return string
	 */
	public function full_title() {
		
		$post_id = $this->post_id;
		$post    = get_post( $post_id );
		$title   = $post->post_title;
		
		if ( $this->subtitle )
			$title = $title . ' - ' . $this->subtitle;
		
		return $title;
	}

	public function description() {
	
	  if ( $this->summary ) {
	    $description = $this->summary;
	  } elseif ( $this->subtitle ) {
	    $description = $this->subtitle;
	  } else {
	    $description = get_the_title();
	  }
	
	  return htmlspecialchars( trim( $description ) );
	}

	public function explicitText() {

		if ($this->explicit == 2)
			return 'clean';

		return $this->explicit ? 'yes' : 'no';
	}

	public function media_files() {
		global $wpdb;
		
		$media_files = array();
		
		$sql = '
			SELECT M.*
			FROM ' . MediaFile::table_name() . ' M
				JOIN ' . EpisodeAsset::table_name() . ' A ON A.id = M.episode_asset_id
			WHERE M.episode_id = \'' . $this->id . '\'
			ORDER BY A.position ASC
		';

		$rows = $wpdb->get_results( $sql );
		
		if ( ! $rows ) {
			return array();
		}
		
		foreach ( $rows as $row ) {
			$model = new MediaFile();
			$model->flag_as_not_new();
			foreach ( $row as $property => $value ) {
				$model->$property = $value;
			}
			$media_files[] = $model;
		}
		
		return $media_files;
	}

	/**
	 * Get episode related to the current global post object.
	 */
	public static function get_current() {
		if (is_single()) {
			return self::find_one_by_post_id(get_the_ID());
		} else {
			return null;
		}
	}

	public static function find_or_create_by_post_id( $post_id ) {
		$episode = Episode::find_one_by_property( 'post_id', $post_id );

		if ( $episode )
			return $episode;

		$episode = new Episode();
		$episode->post_id = $post_id;
		$episode->save();

		return $episode;
	}

	public function enclosure_url( $episode_asset ) {
		$media_file = MediaFile::find_by_episode_id_and_episode_asset_id( $this->id, $episode_asset->id );
		return $media_file->get_file_url();
	}

	public function get_cover_art_with_fallback() {

		if ( ! $image = $this->get_cover_art() )
			$image = Podcast::get_instance()->cover_image;

		return $image;
	}

	public function get_cover_art() {
		
		$podcast = Podcast::get_instance();
		$asset_assignment = AssetAssignment::get_instance();

		if ( ! $asset_assignment->image )
			return;
		
		if ( $asset_assignment->image == 'manual' )
			return $this->cover_art;

		$cover_art_file_id = $asset_assignment->image;
		if ( ! $asset = EpisodeAsset::find_one_by_id( $cover_art_file_id ) )
			return false;

		if ( ! $file = MediaFile::find_by_episode_id_and_episode_asset_id( $this->id, $asset->id ) )
			return false;

		return ( $file->size > 0 ) ? $file->get_file_url() : false;
	}

	/**
	 * Get episode chapters.
	 * 
	 * @param  string $format object, psc, mp4chaps, json. Default: object
	 * @return mixed
	 */
	public function get_chapters( $format = 'object' ) {
		$chapters_manager = new ChaptersManager( $this );
		return $chapters_manager->get( $format );
	}

	public function refetch_files() {

		$valid_files = array();
		foreach ( EpisodeAsset::all() as $asset ) {
			if ( $file = MediaFile::find_by_episode_id_and_episode_asset_id( $this->id, $asset->id ) ) {
				$file->determine_file_size();
				$file->save();
				
				if ( $file->is_valid() )
					$valid_files[] = $file->id;
			}
		}

		if ( empty( $valid_files ) && get_post_status( $this->post_id ) == 'publish' )
			Log::get()->addAlert( 'All assets for this episode are invalid!', array( 'episode_id' => $this->id ) );
	}

	public function get_duration( $format = 'HH:MM:SS' ) {
		$duration = new \Podlove\Duration( $this->duration );
		return $duration->get( $format );
	}

	public function delete_caches() {

		// delete caches for current episode
		delete_transient( 'podlove_chapters_string_' . $this->id );

		// delete caches for revisions of this episode
		if ( $revisions = wp_get_post_revisions( $this->post_id ) ) {
			foreach ( $revisions as $revision ) {
				if ( $revision_episode = Episode::find_one_by_post_id( $revision->ID ) ) {
					delete_transient( 'podlove_chapters_string_' . $revision_episode->id );
				}
			}
		}

	}

	/**
	 * Check for basic validity.
	 *
	 * - MUST have an existing associated post
	 * - associated post MUST be of type 'podcast'
	 * - MUST NOT be deleted/trashed
	 * 
	 * @return boolean
	 */
	public function is_valid() {

		$post = get_post( $this->post_id );

		if ( ! $post )
			return false;

		// skip deleted podcasts
		if ( ! in_array( $post->post_status, array( 'private', 'draft', 'publish', 'pending', 'future' ) ) )
			return false;

		// skip versions
		if ( $post->post_type != 'podcast' )
			return false;

		return true;
	}

	public function get_license()
	{
		$license = new License('episode', array(
			'license_name'         => $this->license_name,
			'license_url'          => $this->license_url
		));

		return $license;
	}

	public function get_license_picture_url() {
		return $this->get_license()->getPictureUrl();
	}

	public function get_license_html() {
		return $this->get_license()->getHtml();
	}	
}

Episode::property( 'id', 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' );
Episode::property( 'post_id', 'INT' );
Episode::property( 'subtitle', 'TEXT' );
Episode::property( 'summary', 'TEXT' );
Episode::property( 'enable', 'INT' ); // listed in podcast directories or not?
Episode::property( 'slug', 'VARCHAR(255)' );
Episode::property( 'duration', 'VARCHAR(255)' );
Episode::property( 'cover_art', 'VARCHAR(255)' );
Episode::property( 'chapters', 'TEXT' );
Episode::property( 'recording_date', 'DATETIME' );
Episode::property( 'explicit', 'TINYINT' );
Episode::property( 'license_name', 'TEXT' );
Episode::property( 'license_url', 'TEXT' );
