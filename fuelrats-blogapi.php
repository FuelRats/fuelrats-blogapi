<?php
header( 'Content-type: application/json' );
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

	public function get_posts( $data ) {
		global $wpdb;


		$page = 1;
		$pageSize = 10;
		if ( ! empty( $data['page'] ) && intval( $data['page'] ) != 0 ) {
			$page = intval( $data['page'] );
		}

		if ( ! empty( $data['pagesize'] ) && intval( $data['pagesize'] ) != 0 ) {
			$pageSize = intval( $data['pagesize'] );
		}

		$filterSql = '';

		if ( ! empty( $data['category'] ) && intval( $data['category'] ) != 0 ) {
			$filterSql .= ' AND t.term_id = ' . intval( $data['category'] );
		}

		if ( ! empty( $data['author'] ) && intval( $data['author'] ) != 0 ) {
			$filterSql .= ' AND p.post_author = ' . intval( $data['author'] );
		}

		if ( ! empty( $data['id'] ) && intval( $data['id'] ) != 0 ) {
			$filterSql .= ' AND p.ID = ' . intval( $data['id'] );
		}

		if ( ! empty( $data['slug'] ) ) {
			$filterSql .= $wpdb->prepare( ' AND p.post_name = %s', $data['slug'] );
		}

		$pageSql = ' LIMIT ' . ( ( $page - 1 ) * $pageSize ) . ', ' . $pageSize;

		$sql = "SELECT COUNT(ID) FROM $wpdb->posts WHERE `post_type` = 'post' AND `post_status` = 'publish'";
		$sql = "SELECT COUNT(DISTINCT p.ID)
FROM {$wpdb->posts} p
INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
LEFT JOIN {$wpdb->term_relationships} trs ON p.ID = trs.object_id
LEFT JOIN {$wpdb->term_taxonomy} tt ON trs.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
WHERE p.`post_type` = 'post' AND p.`post_status` = 'publish'
{$filterSql}
";
		$total_posts = $wpdb->get_var( $sql );

$sql = "
SELECT p.ID AS id, p.post_author AS author, p.post_date_gmt as date_gmt, p.post_title as title, p.post_content as `content`, p.post_name as slug,
u.ID AS author_id, u.display_name AS author_name,
GROUP_CONCAT(t.term_id SEPARATOR ';;') category,
GROUP_CONCAT(t.term_id SEPARATOR ';;') category_ids,
GROUP_CONCAT(t.name SEPARATOR ';;') category_names,
GROUP_CONCAT(tt.description SEPARATOR ';;') category_descriptions
FROM {$wpdb->posts} p
INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
LEFT JOIN {$wpdb->term_relationships} trs ON p.ID = trs.object_id
LEFT JOIN {$wpdb->term_taxonomy} tt ON trs.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
WHERE p.`post_type` = 'post' AND p.`post_status` = 'publish'
{$filterSql}
GROUP BY p.ID
ORDER BY p.`ID` DESC
{$pageSql}
";
		$data = $wpdb->get_results( $sql, ARRAY_A );
		return $this->get_jsonapi_format(
			$data,
			$total_posts,
			'posts',
			array(
				'date_gmt',
				'title',
				'content',
				'slug',
			),
			array(
				array(
					'author',
					'people',
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
			)
		);
	}

	private function get_jsonapi_format( $data, $count, $type, $attributes = array(), $relationships = array() ) {
		$jsonApiResponse = new stdClass();
		$jsonApiResponse->meta = array(
			'total-pages' => ceil( round( $count / 10, 4 ) )
		);
		$jsonApiResponse->data = array();

		$uniqueIncludes = array();

		foreach ( $data as $d ) {
			$jsondata = new stdClass();
			$jsondata->type = $type;
			$jsondata->id = $d['id'];
			$jsondata->attributes = array();
			foreach ( $attributes as $attr ) {
				$jsondata->attributes[$attr] = $d[$attr];
			}

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
				$jsondata->relationships[$relation[0]] = $rel;

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
