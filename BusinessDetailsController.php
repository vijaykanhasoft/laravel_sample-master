/*
* This is BusinessDetails Controller
* Here logic of get business detail
* This is php laravel freamwork
*/
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

use \App\Classes\Web\Helper;
use \App\Classes\Web\IMF;
use \App\Classes\Common\Alfred;
use \App\Classes\Common\Mailinator;
use \App\Classes\Common\DataAmbassador as Da;
use \App\Models\AccessToken;
use \App\Models\Market;

use Illuminate\Http\Request;

use Log;
use Twig;

class BusinessDetailsController extends Controller
{

	/**
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function index( Request $req )
	{

		return redirect( env('APP_URL') );
		
		$domain = (string) $req->get('website', '');
		$r = false;
		if ( $req->session()->has('agencies_demo_token') ) {
		    $r = AccessToken::useCredit( $req->session()->get('agencies_demo_token'), 1 );
		    if ( $r === null )
		    	return redirect('/agencies');
		}

		$domain = decrypt($domain);

		$mktMdl = app('\App\Models\Market');
		$grMdl= app('\App\Models\GabaRegion');

		$regionCode = $grMdl->exists ? $grMdl->code : '';
		$marketCode = $mktMdl->exists ? $mktMdl->code : '';

		$regions = Helper::handleEmptyRegionCodeAndReturnRegions( $marketCode, $domain, $regionCode, 'agencies/business-details' );
		Helper::formatDomainAndRedirect( $domain, $regionCode, $marketCode, 'agencies/business-details' );

		$domainData = Da::fetchDomainData( $domain, $marketCode, $regionCode );

		$topCompetitors = (object)[];
		$topCompetitors->p['scores'] = $domainData->p['scoreboard']['topCompetitors'];
		$topCompetitors->p['size'] = $domainData->p['scoreboard']['population'];
		$topCompetitors->dir = $domainData->dir;

		$historicalData = Alfred::getWebsiteHistoricalData( $domain, $marketCode, $regionCode );

		if ( !$mktMdl->exists ) {
			$mktMdl = Market::target( $marketCode )->first();
		}

		$market = $mktMdl->toArray();

		return Twig::render('web/agencies/demo_dashboard.twig', [
			'website' => $domain,
			'regionCode' => $regionCode,
			'marketCode' => $marketCode,
			'industry' => Helper::slugify($market['industry']),
			'occupation' => Helper::slugify($market['occupation_lvl_1']),

			'scoreInfo' => $domainData->p,
			'domain' => $domain,
			'regions' => $regions,
			'credits' => $r,

			'historical_data_for_graph' => (empty($historicalData) || count($historicalData) == 1) ? Helper::$fakeHistoricalData : $historicalData,
			'historical_data' => (empty($historicalData) || count($historicalData) == 1) ? Helper::$fakeHistoricalData : [],
			'fake_historical_data' => (empty($historicalData) || count($historicalData) == 1) ? Helper::$fakeHistoricalData : [],
			'score_changes_by_date' => Helper::formatScoreChangeByDate( $historicalData ),

			'scoreInfo' => $domainData->p,
			'fixits' => $domainData->p['fixits'],
			'regionViewableName' =>	$domainData->p['region']['user_viewable_name'],

			'topCompetitorsObj' => $topCompetitors,
			'topCompetitors' => $topCompetitors->p,
			'topCompBusinesses' => $topCompetitors->dir,
			]);
	}

}
