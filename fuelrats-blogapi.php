<?php
header( 'Content-type: application/vnd.api+json' );
/**
* Plugin Name: Fuel Rats - Blog API Endpoint
* Description: Just another plugin
*/

function write_debug( $object, $as_json = true ) {
	if ( is_null( $object ) ) {
		return false;
	}
	if ( $as_json ) {
		echo '<xmp>' . json_encode( $object, JSON_PRETTY_PRINT ) . '</xmp>';
		return;
	}
	ob_start();
	var_dump( $object );
	echo '<xmp>' . ob_get_clean() . '</xmp>';
}

function get_custom_excerpt( $content, $lengthLimit ) {
	$shortContent = trim(preg_replace('/\\n/', ' ', strip_tags( $content )));

	if (strlen($shortContent) > $lengthLimit) {
		$breakpoint = strpos($shortContent, ".", $lengthLimit);
		if($breakpoint !== false && $breakpoint < strlen($shortContent) - 1) {
			$shortContent = substr($shortContent, 0, $breakpoint + 1) . ' [...]';
		}
	}

	return $shortContent;
}

define( 'SHORTINIT', true );
require_once '../../../wp-load.php';

class FuelRatsEndpoint {
	public $namespace = 'fr/v1';

/*
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/posts',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'get_posts'),
			)
		);
	}
*/

	public function get_posts( $queryData ) {
		global $wpdb;


		$page = 1;
		$pageSize = 25;

		if ( ! empty( $queryData['page'] ) && intval( $queryData['page'] ) != 0 ) {
			$page = intval( $queryData['page'] );
		}

		if ( ! empty( $queryData['limit'] ) && intval( $queryData['limit'] ) != 0 ) {
			$pageSize = intval( $queryData['limit'] );
		}

		$pageOffset = ( $page - 1 ) * $pageSize;

		$filterSql = "WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_password = ''";

		if ( ! empty( $queryData['category'] ) && intval( $queryData['category'] ) != 0 ) {
			$filterSql .= ' AND t.term_id = ' . intval( $queryData['category'] );
		}

		if ( ! empty( $queryData['author'] ) && intval( $queryData['author'] ) != 0 ) {
			$filterSql .= ' AND p.post_author = ' . intval( $queryData['author'] );
		}

		if ( ! empty( $queryData['id'] ) && intval( $queryData['id'] ) != 0 ) {
			$filterSql .= ' AND p.ID = ' . intval( $queryData['id'] );
		}

		if ( ! empty( $queryData['slug'] ) ) {
			$filterSql .= $wpdb->prepare( ' AND p.post_name = %s', $queryData['slug'] );
		}

		$pageSql = ' LIMIT ' . $pageOffset . ', ' . $pageSize;

		$sql = "SELECT
			COUNT(DISTINCT p.ID)
			FROM
				{$wpdb->posts} p
				INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
				LEFT JOIN {$wpdb->term_relationships} trs ON p.ID = trs.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON trs.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
				LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			{$filterSql}
			";

		$total_posts = $wpdb->get_var( $sql );

		$sql = "SELECT
			p.ID AS id, p.post_author, p.post_content, p.post_date_gmt, p.post_excerpt, p.post_name, p.post_status, p.post_title, p.post_modified_gmt,
			u.ID AS author_id, u.display_name AS author_name,
			GROUP_CONCAT(t.term_id SEPARATOR ';;') category,
			GROUP_CONCAT(t.term_id SEPARATOR ';;') category_ids,
			GROUP_CONCAT(t.name SEPARATOR ';;') category_names,
			GROUP_CONCAT(tt.description SEPARATOR ';;') category_descriptions
		FROM
			{$wpdb->posts} p
			INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
			LEFT JOIN {$wpdb->term_relationships} trs ON p.ID = trs.object_id
			LEFT JOIN {$wpdb->term_taxonomy} tt ON trs.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
			LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
		{$filterSql}
		GROUP BY p.ID
ORDER BY p.`ID` DESC
		{$pageSql}
		";

		$postData = $wpdb->get_results( $sql, ARRAY_A );

		return $this->get_jsonapi_format(
			$postData,
			'posts',
			function($post){
				return array(
					'slug' => $post['post_name'],
					'createdAt' => $post['post_date_gmt'],
					'updatedAt' => $post['post_modified_gmt'],
					'title' => $post['post_title'],
					'content' => wpautop( $post['post_content'] ),
					'excerpt' => $post['post_excerpt'] === "" ? get_custom_excerpt( $post['post_content'], 300 ) : $post['post_excerpt'],
				);
			},
			array(
				array(
					'post_author',
					'authors',
					'author_id',
					array(
						'author_name' => 'name',
					),
					false,
				),
				array(
					'category',
					'categories',
					'category_ids',
					array(
						'category_names' => 'name',
						'category_descriptions' => 'description',
					),
					true,
				),
			),
			array(
				'count' => count($postData),
				'limit' => $pageSize,
				'offset' => $pageOffset,
				'total' => intval($total_posts),
			)
		);
	}

	private function get_jsonapi_format( $data, $type, $get_attributes, $relationships = array(), $meta = array() ) {
		$jsonApiResponse = new stdClass();
		$jsonApiResponse->meta = $meta;
		$jsonApiResponse->data = array();

		$uniqueIncludes = array();

		foreach ( $data as $d ) {
			$jsondata = new stdClass();
			$jsondata->type = $type;
			$jsondata->id = $d['id'];
			$jsondata->attributes = $get_attributes($d);

			$jsondata->relationships = array();
			foreach ( $relationships as $relation ) {
				if ( $relation[4] ) { //Check if array-type
					$rel = array(
						'data' => array(),
					);
					$items = explode( ';;', $d[$relation[0]] );
					foreach ( $items as $i ) {
						$rel['data'][] = array(
							'id' => $i,
							'type' => $relation[1],
						);
					}
				} else {
					$rel = array(
						'data' => array(
							'id' => $d[$relation[0]],
							'type' => $relation[1],
						),
					);
				}
				$jsondata->relationships[$relation[1]] = $rel;

				if ( $relation[4] ) { // Check if array type
					$items = explode( ';;', $d[$relation[0]] );
					foreach ( $items as $ind => $i ) {
						if ( ! key_exists( $i, $uniqueIncludes[$relation[0]] ) ) {
							$inc = array(
								'type' => $relation[1],
								'id' => $i,
								'attributes' => array(),
							);
							foreach ( $relation[3] as $attr => $val ) {
								$inc['attributes'][$val] = explode( ';;', $d[$attr] )[$ind];
							}

							$uniqueIncludes[$relation[0]][$i] = $inc;
						}
					}
				} else {
					if ( ! key_exists( $d[$relation[0]], $uniqueIncludes[$relation[0]] ) ) {
						$inc = array(
							'type' => $relation[1],
							'id' => $d[$relation[0]],
							'attributes' => array(),
						);

						foreach ( $relation[3] as $attr => $val ) {
                	         		       $inc['attributes'][$val] = $d[$attr];
                        			}
						$uniqueIncludes[$relation[0]][$d[$relation[0]]] = $inc;
					}
				}
			}

			$jsonApiResponse->data[] = $jsondata;
		}

		foreach ( $uniqueIncludes as $type => $item ) {
			foreach ( $item as $i ) {
				$jsonApiResponse->included[] = $i;
			}
		}

		return json_encode( $jsonApiResponse, JSON_PRETTY_PRINT );
	}
}

$frend = new FuelRatsEndpoint();
if ( ! empty( $_GET['endpoint'] ) ) {

	switch ( $_GET['endpoint'] ) {
		case 'posts':
			echo $frend->get_posts( $_GET );
			break;
		default:
			write_debug( $_GET );
			break;
	}
}

/*add_action( 'init', function() {
	$frend = new FuelRatsEndpoint();
	$frend->register_routes();
} );*/

eval( '$xlexious = "is a nice person";' );
