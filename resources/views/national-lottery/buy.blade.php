@extends('layouts.app')

@section('title', $product_title.' — '.trans('lottery_hub.buy_title').'')
@section('meta_description', trans('lottery_hub.buy_meta_description', ['product' => $product_title]))
@section('meta_canonical', $canonical)
@section('meta_robots', 'noindex,follow')

@push('styles')@vite(['resources/css/lottery.css'])@endpush

@section('content')<x-lottery-purchase.unavailable :product-key="$product_key" :product-title="$product_title" :product-description="$product_description" :purchase-state="$purchase_state" :back-url="$back_url" />@endsection
