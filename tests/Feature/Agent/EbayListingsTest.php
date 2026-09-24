<?php

use App\Agent\EbayListings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$credentials = ['clientId' => 'ebay-id', 'clientSecret' => 'ebay-secret'];

beforeEach(fn () => EbayListings::forgetToken());

it('keeps the application token in memory, never in the cache', function () use ($credentials) {
    Cache::spy();
    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'token-1', 'expires_in' => 7200]),
        EbayListings::SearchUrl.'*' => Http::response(['itemSummaries' => []]),
    ]);

    $ebay = app(EbayListings::class);
    $ebay->search($credentials, 'walkman');
    $ebay->search($credentials, 'cassette', 3);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === EbayListings::TokenUrl
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ebay-id:ebay-secret'))
        && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'https://api.ebay.com/oauth/api_scope');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), EbayListings::SearchUrl)
        && $request['q'] === 'cassette' && (int) $request['limit'] === 3
        && $request->hasHeader('Authorization', 'Bearer token-1')
        && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_US'));

    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('get');
    expect(DB::table('cache')->count())->toBe(0);
});

it('fetches a new token once the old one is within a minute of expiring', function () use ($credentials) {
    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'short-lived', 'expires_in' => 60]),
        EbayListings::SearchUrl.'*' => Http::response(['itemSummaries' => []]),
    ]);

    $ebay = app(EbayListings::class);
    $ebay->search($credentials, 'walkman');
    $ebay->search($credentials, 'walkman');

    Http::assertSentCount(4);
});

it('fetches a new token when the credentials change', function () use ($credentials) {
    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'token', 'expires_in' => 7200]),
        EbayListings::SearchUrl.'*' => Http::response(['itemSummaries' => []]),
    ]);

    $ebay = app(EbayListings::class);
    $ebay->search($credentials, 'walkman');
    $ebay->search(['clientId' => 'other', 'clientSecret' => 'secret'], 'walkman');

    Http::assertSentCount(4);
});

it('maps listings to cents with shipping and defaults', function () use ($credentials) {
    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'token', 'expires_in' => 7200]),
        EbayListings::SearchUrl.'*' => Http::response(['total' => 42, 'itemSummaries' => [
            ['title' => 'Walkman', 'price' => ['value' => '19.99', 'currency' => 'USD'], 'condition' => 'Used', 'itemWebUrl' => 'https://ebay.com/itm/1'],
            ['title' => 'Bare listing'],
        ]]),
    ]);

    expect(app(EbayListings::class)->search($credentials, 'walkman'))->toBe([
        'listings' => [
            ['title' => 'Walkman', 'priceCents' => 1999, 'shippingCents' => null, 'currency' => 'USD', 'condition' => 'Used', 'url' => 'https://ebay.com/itm/1'],
            ['title' => 'Bare listing', 'priceCents' => null, 'shippingCents' => null, 'currency' => 'USD', 'condition' => null, 'url' => null],
        ],
        'total' => 42,
    ]);
});

it('reports a token failure to the model instead of throwing', function () use ($credentials) {
    Http::fake([EbayListings::TokenUrl => Http::response([], 401)]);

    expect(app(EbayListings::class)->search($credentials, 'walkman'))
        ->toBe(['listings' => [], 'total' => 0, 'error' => 'eBay token request failed with status 401']);
});

it('reports a search failure to the model instead of throwing', function () use ($credentials) {
    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'token', 'expires_in' => 7200]),
        EbayListings::SearchUrl.'*' => Http::response([], 503),
    ]);

    expect(app(EbayListings::class)->search($credentials, 'walkman'))
        ->toBe(['listings' => [], 'total' => 0, 'error' => 'eBay search failed with status 503']);
});
