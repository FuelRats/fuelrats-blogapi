# FuelRats BlogAPI endpoint

This simple little PHP-script is whipped up to serve WordPress-posts by doing a short-init of WP (load as little as possible).

It uses JSONAPI to display the data.

## Example usage

### Installation
- Put the file inside of `/wp-content/plugins/<yourdirectoryofchoosing>/`


### Usage
To fetch data from the API, you have to surf to:

`//<yourdomain>/wp-content/plugins/<yourdirectoryofchoosing>/fuelrats-blogapi.php?endpoint=posts`

#### Available query string parameters that modify the query
| Parameter | Type | Description | Default |
| :-------: | :--: | ----------- | ------: |
| page | int | The page of the paged data | 1 |
| pageSize | int | The page size, to modify the number of posts returned | 10 |
| category | int | The category ID from wordpress | `null` |
| author | int | The author ID from wordpress | `null` |
| id | int | The post ID from the blog post | `null` |
| slug | string | The slug of a blog post | `null` |

So, to fetch `10` posts, from page `2`, where the category is `5` and the author is `55`


`?page=2&pageSize=10&category=5&author=55`
