/*
* This is enterprise dashboard Controller
* Here logic of get entripse dashboard detail send on request with location and domain
* This is php laravel freamwork
*/

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

use \App\Classes\Common\Alfred;
use \App\Classes\Web\Helper;
use \App\Classes\Common\Mailinator;
use \App\Classes\Web\Rs;
use \App\Classes\Common\DataAmbassador as Da;
use \App\Classes\Backend\Exceptions\PublicException;

use Log;
use Twig;


class DashboardController extends Controller
{
	/**
	 *
	 * @param  int $id
	 * @return Response
	 */
	public function index( Request $req )
	{
		$user = Auth::user();
		$req->session()->put( 'user', $user->toArray() );
		$req->session()->put( 'user.rel_business', $user->rel_business->toArray() );
		$req->session()->put( 'user.default_website', $user->rel_default_website->toArray() );

		$domain = $user->rel_default_website->domain;

		if ( $user->rel_default_region === null ) {
			return redirect( "/business-details" );
		}

		if ( $user->rel_default_market === null ) {
			return redirect( "/business-details" );
		}

		$marketCode = $user->rel_default_market->code;
		$marketId = $user->rel_default_market->id;
		$req->session()->put( 'user.default_market', $user->rel_default_market->toArray() );

		$req->session()->put( 'user.default_region', $user->rel_default_region->toArray() );
		$regionCode = $user->rel_default_region->code;
		$req->session()->put( 'regionCode', $regionCode );


		$regions = Helper::handleEmptyRegionCodeAndReturnRegions( $marketCode, $domain, $regionCode, 'dashboard' );
		$req->session()->put( 'user.regions', $regions );

		if ( $req->session()->has( 'intro_competitors' ) ) {
			$comps = json_decode( $req->session()->get( 'intro_competitors' ), true );
			if ( ! empty( $comps ) ) {
				foreach ( $comps as $cId => $c ) {
					try {
						$r = $user->addCompetitor( $cId, $regionCode );
					} catch ( PublicException $e ) {
						$req->session()->put( 'adding_comp_errors', "Unable to add competitor with business id $cId." );
					} catch ( \Exception $e ) {
						//another session message here
					}
				}
				$req->session()->forget( 'intro_competitors' );
			}
		}

		return Twig::render( 'web/dashboard.twig', [
			'regionCode' => $regionCode,
			'website' => $domain,
			'marketCode' => $marketCode,
			'industry' => Helper::slugify( $user->rel_default_market->industry ),
			'occupation' => Helper::slugify( $user->rel_default_market->occupation_lvl_1 )
		] );

	}

	public function fixitRedirectDisclaimer( Request $req )
	{
		return Twig::render( 'web/redirect_disclaimer.twig', [
			'marketCode' => $req->session()->get( 'user.default_market.code' ),
			'industry' => Helper::slugify( $req->session()->get( 'user.default_market.industry' ) ),
			'occupation' => Helper::slugify( $req->session()->get( 'user.default_market.occupation_lvl_1' ) )
		] );
	}


	public function ajaxUpdateEmailAlerts( Request $req, $onOff )
	{
		$user = Auth::user();
		$user->receives_score_change_emails = ( $onOff == 1 ? true : false );
		$user->save();
		$req->session()->put( 'user', $user->toArray() );

		return response( [ 'msg' => 'Ok!' ], 200 );
	}

	private static function fetchDomainDatafromSessionOrRanker( Request $req, $domain, $marketCode, $regionCode )
	{

		$domainData = Da::fetchDomainData( $domain, $marketCode, $regionCode );
		return $domainData;

	}

	public function ajaxScore( Request $req, $regionCode = null )
	{
		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );

		$domainData = $this->fetchDomainDatafromSessionOrRanker( $req, $domain, $marketCode, $regionCode );

		return Twig::render( 'web/dashboard/top_section.twig', [
			'scoreInfo' => $domainData->p,
			'domain' => $domain,
			'regions' => $req->session()->get( 'user.regions' ),
			'marketCode' => $marketCode,
			'industry' => Helper::slugify( $req->session()->get( 'user.default_market.industry' ) ),
			'occupation' => Helper::slugify( $req->session()->get( 'user.default_market.occupation_lvl_1' ) )
		] );
	}


	public function ajaxFixits( Request $req, $regionCode = null )
	{

		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );

		$domainData = $this->fetchDomainDatafromSessionOrRanker( $req, $domain, $marketCode, $regionCode );

		$fixits = $domainData->p[ 'fixits' ];
		return Twig::render( 'web/dashboard/fixits.twig', [
			'scoreInfo' => $domainData->p,
			'domain' => $domain,
			'fixits' => $fixits,
			'marketCode' => $marketCode,
			'regionViewableName' => $domainData->p[ 'region' ][ 'user_viewable_name' ],
			'industry' => Helper::slugify( $req->session()->get( 'user.default_market.industry' ) ),
			'occupation' => Helper::slugify( $req->session()->get( 'user.default_market.occupation_lvl_1' ) )
		] );
	}

	public function ajaxHistograph( Request $req, $regionCode = null )
	{
		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );
		if ( ! $regionCode )
			$regionCode = $req->session()->get( 'regionCode' );
		
		$is_verified = $req->session()->has( 'user.rel_business' ) ? $req->session()->get( 'user.rel_business.is_verified' ) : true;

		$data = [
			'website' => $domain,
			'receives_score_change_emails' => $req->session()->get( 'user.receives_score_change_emails' )
		];

		if ( ! $is_verified ) {
			$historicalData = Helper::$fakeHistoricalData;
		} else {
			$historicalData = Alfred::getWebsiteHistoricalData( $domain, $marketCode, $regionCode );
		}

		if ( empty( $historicalData ) || count( $historicalData ) == 1 || ! $is_verified ) {
			$historicalData = Helper::$fakeHistoricalData;
			$data += [
				'historical_data' => [ ],
				'fake_historical_data' => $historicalData
			];
		} else {
			$data += [
				'historical_data' => $historicalData,
				'fake_historical_data' => [ ]
			];
		}

		$data += [ 'score_changes_by_date' => Helper::formatScoreChangeByDate( $historicalData ) ];

		$historical_data_html = Twig::render( 'web/dashboard/histograph.twig', $data );

		return json_encode( [
			'historical_data' => $historicalData,
			'graph_html' => $historical_data_html
		] );
	}

	public function ajaxCompetitors( Request $req, $regionCode = null )
	{
		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );

		$domainData = $this->fetchDomainDatafromSessionOrRanker( $req, $domain, $marketCode, $regionCode );

		$topCompetitors = $this->extractCompetitorsFromDomainData( $domainData );
		$req->session()->put( 'user.topCompetitors', $topCompetitors );

		return Twig::render( 'web/dashboard/competitors.twig', [
			'topCompetitors' => $topCompetitors->p,
			'topCompBusinesses' => $topCompetitors->dir,
			'marketCode' => $marketCode,
			'industry' => Helper::slugify( $req->session()->get( 'user.default_market.industry' ) ),
			'occupation' => Helper::slugify( $req->session()->get( 'user.default_market.occupation_lvl_1' ) ),
			'domain' => $req->session()->get( 'user.default_website.domain' ),
			'regionViewableName' => $domainData->p[ 'region' ][ 'user_viewable_name' ]
		] );
	}

	public function ajaxCompetitorsJson( Request $req, $regionCode = null )
	{
		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );
		
		if ( $req->session()->has( 'user.topCompetitors' ) )
			return json_encode( $req->session()->get( 'user.topCompetitors' ) );

		$domainData = $this->fetchDomainDatafromSessionOrRanker( $req, $domain, $marketCode, $regionCode );
		$topCompetitors = $this->extractCompetitorsFromDomainData( $domainData );
		$req->session()->put( 'user.topCompetitors', $topCompetitors );

		return json_encode( $topCompetitors );

		// $marketCode = $req->session()->get('user.default_market.code');
		// return json_encode(Da::fetchScoreboardRange( $marketCode, $regionCode, 0, 9 ));
	}

	public function ajaxCustomCompetitors( Request $req, $regionCode = null )
	{
		if ( ! $regionCode )
			$regionCode = $req->session()->get( 'regionCode' );

		$marketCode = $req->session()->get( 'user.default_market.code' );
		$gabaCode = urldecode( $regionCode );
		$domain = $req->session()->get( 'user.default_website.domain' );

		$user = Auth::user();
		$data = [ ];
		Cache::forget( $domain . '_customCompetitors' );

		$watchedDomains = $user->listWatchedCompetitorWebsiteDomains( $gabaCode );
		array_push( $watchedDomains, $domain );
		if ( ! empty( $watchedDomains ) ) {
			$customCompetitors = Da::fetchScoreboardTargetted( $watchedDomains, $marketCode, $gabaCode );
			$data = [
				'customCompetitors' => $customCompetitors->p,
				'customCompBusinesses' => $customCompetitors->dir,
				'domain' => $domain
			];

			Cache::put( $user->id . '_customCompetitors', $customCompetitors, 60 * 60 );
		}

		$regionViewableName = '';
		if ( $req->session()->has( 'user.domainData' ) ) {
			$domainData = $req->session()->get( 'user.domainData' );
			$regionViewableName = $domainData->p[ 'region' ][ 'user_viewable_name' ];
		}

		$data[ 'occupation' ] = Helper::slugify( $req->session()->get( 'user.default_market.occupation_lvl_1' ) );
		$data[ 'regionViewableName' ] = $regionViewableName;

		return Twig::render( 'web/dashboard/custom_competitors.twig', $data );
	}

	public function ajaxCustomCompetitorsJson( Request $req, $regionCode = null )
	{
		$user = Auth::user();
		$domain = $req->session()->get( 'user.default_website.domain' );
		$marketCode = $req->session()->get( 'user.default_market.code' );
		if ( ! $regionCode ) {
			return json_encode( Cache::get( $user->id . '_customCompetitors' ) );
		}

		$gabaCode = urldecode( $regionCode );

		$user = Auth::user();
		$data = [ ];

		$watchedDomains = $user->listWatchedCompetitorWebsiteDomains( $gabaCode );
		array_push( $watchedDomains, $domain );
		if ( ! empty( $watchedDomains ) )
			return json_encode( Da::fetchScoreboardTargetted( $watchedDomains, $marketCode, $gabaCode ) );

		return json_encode( [ ] );
	}

	public function ajaxDeleteCompetitor( Request $req, $compDomain, $regionCode = null )
	{
		if ( ! $regionCode )
			$regionCode = $req->session()->get( 'regionCode' );

		$gabaCode = urldecode( $regionCode );

		$user = Auth::user();
		$user->deleteCompetitorByWebsiteDomain( $compDomain, $gabaCode );
		return response( [ 'msg' => 'Competitor was removed from your list.' ], 200 );
	}

	public function ajaxAddCompetitor( Request $req, $businessId, $regionCode = null )
	{
		if ( ! $regionCode )
			$regionCode = $req->session()->get( 'regionCode' );

		$gabaCode = urldecode( $regionCode );

		$user = Auth::user();

		try {
			$r = $user->addCompetitor( $businessId, $gabaCode );
		} catch ( PublicException $e ) {
			// return Rs::er( $e->getMessage(), 400 );
			return response( $e->getMessage(), 400 );
		} catch ( \Exception $e ) {
			return Rs::er( 'There was a problem with processing your request, please try again later', 500, $data = $e->getMessage() );
		}

		return response( [ 'msg' => 'Competitor added' ], 200 );
	}


	public function updateDefaultRegion( Request $req, $regionCodeId )
	{
		$user = Auth::user();
		$user->default_gaba_region_id = ( $regionCodeId );
		$user->save();
		$req->session()->put( 'user.default_region', $user->rel_default_region->toArray() );
		return response( [ 'msg' => 'Default region updated' ], 200 );
	}


	public function ajaxNewAddressForm( Request $req )
	{
		return Twig::render( 'web/dashboard/new_address_form.twig' );
	}

	public function ajaxNewAddress( Request $req )
	{

		$user = Auth::user();
		$submittedAddress = (string)$req->get( 'address', '' );

		Log::info( "Received new Business Location Request by user '$user->email' for address: '$submittedAddress'" );
		return response( [ 'msg' => 'Success' ], 200 );
	}

	public function ajaxContact( Request $req )
	{

		$user = Auth::user();
		$message = (string)$req->get( 'message', '' );

		// prepare e-mail data
		$mailData = [
			'user' => $user->toArray(),
			'business' => $user->rel_business->toArray(),
			'message' => $message
		];

		Mailinator::sendToAdmins( 'contact', $mailData, [ 'email' => $user->email ] );

		return response( [ 'msg' => 'Success' ], 200 );
	}

	public function ajaxCaptureFixit( Request $req )
	{

		$domain = $req->session()->get( 'user.default_website.domain' );
		$email = $req->session()->get( 'user.email' );
		$gabaCode = $req->session()->get( 'regionCode' );
		$domainData = $req->session()->get( 'user.domainData' );
		$fixitSlug = (string)$req->get( 'fixitSlug', '' );

		$logMsg = "New fixit request: Website $domain asked $fixitSlug";

		if ( $email )
			$logMsg .= ' email=' . $email;
		if ( $gabaCode )
			$logMsg .= ' regionCode=' . $gabaCode;

		Log::info( $logMsg );

		return response( [ 'msg' => 'Ok!' ], 200 );
	}

	private static function extractCompetitorsFromDomainData( $domainData )
	{
		$topCompetitors = (object)[ ];
		$topCompetitors->p[ 'scores' ] = $domainData->p[ 'scoreboard' ][ 'topCompetitors' ];
		$topCompetitors->p[ 'size' ] = $domainData->p[ 'scoreboard' ][ 'population' ];
		$topCompetitors->dir = $domainData->dir;
		return $topCompetitors;
	}
}