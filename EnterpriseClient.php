/*
* This is model of EnterpriseClient
* This file use to save, get, update Enterprise Client
* Here defiend EnterpriseClient table column name with database query
* This is php laravel freamwork
*/
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use \Illuminate\Support\Facades\Validator;
use \App\Classes\Backend\Exceptions\ValidationException;
use Illuminate\Validation\Rule;

class EnterpriseClient extends Model
{
	protected $fillable = [ 'accounting_id', 'client_name', 'description', 'created_at', 'updated_at' ];

	public static function validateClient( array $data, $id = null ): void

	{
		$input = [
			'accounting_id' => $data[ 'accounting_id' ],
			'client_name' => $data[ 'client_name' ],
			'description' => $data[ 'description' ],
		];

		if ( $id ) {
			$v = Validator::make( $input, [
				'accounting_id' => 'string|max:25',
				'client_name' => [ 'required', 'string', 'min:5', 'max:30', Rule::unique( 'enterprise_clients' )->ignore( $id ) ],
				'description' => 'string|max:200'
			] );
		} else {
			$v = Validator::make( $input, [
				'accounting_id' => 'string|max:25',
				'client_name' => 'required|string|unique:enterprise_clients|min:5|max:30',
				'description' => 'string|max:200'
			] );
		}

		if ( ! $v->fails() )
			return;


		throw new ValidationException( $v->errors()->all() );
	}

	static function createClient( array $data, $id = null )
	{
		// static::validateClient( $data, $id );

		if ( $id ) {
			$ec = static::findOrFail( $id );
		} else {
			$ec = new static;
		}
		$ec->accounting_id = $data[ 'accounting_id' ];
		$ec->client_name = $data[ 'client_name' ];
		$ec->description = $data[ 'description' ];
		$ec->save();

		return $ec;
	}

	/*
	================================================
	Scopes
	================================================
	*/

	function scopeSortBy( $q, $sortKey, string $sortDir = 'asc' )
	{
		return $q->orderBy( $sortKey, $sortDir );
	}

	function scopeListingFilter( $q, Request $request )
	{
		if ( $request->has( 'filter_name' ) ) {
			$q->where( function ( $q ) use ( $request ) {
				$name = strtolower( $request->get( 'filter_name' ) );

				$q->where( DB::raw( 'lower(client_name)' ), 'like', '%' . $name . '%' );
				$q->orWhere( DB::raw( 'lower(accounting_id)' ), 'like', '%' . $name . '%' );
			} );
		}
		return $q;
	}
}
