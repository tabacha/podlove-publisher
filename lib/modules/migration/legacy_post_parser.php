<?php
namespace Podlove\Modules\Migration;

/**
 * Parser for WordPress posts from legacy Podcasting plugins like
 * PodPress and PowerPress.
 */
class Legacy_Post_Parser {

	public $post_id;
	private $duration;
	private $post;

	public function __construct( $post_id ) {
		$this->post_id = $post_id;
		$this->post = get_post( $post_id );
		$this->parse();
	}

	private function parse() {

		foreach ( Enclosure::all_for_post( $this->post_id ) as $enclosure ) {
			if ( $enclosure->duration )
				$this->duration = $enclosure->duration;
		}
	}

	function get_duration() {
		return $this->duration;
	}

	function get_subtitle() {
		return get_post_meta( $this->post_id, 'subtitle', true );
	}

	function get_summary() {
		return get_post_meta( $this->post_id, 'summary', true );
	}

}