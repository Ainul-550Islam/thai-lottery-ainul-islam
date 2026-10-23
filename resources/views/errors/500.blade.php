@extends('errors.layout')
@section('code', '500')
@php
    $t500Title = '500 — Server Error';
    $t500Heading = 'Temporary Service Error';
    $t500Body = 'An unexpected error prevented the server from completing your request. Do not assume an in-flight transaction succeeded; review your wallet or ticket ledger once service recovers.';
    try {
        if (function_exists('app') && app()->bound('translator')) {
            $t500Title = trans('errors.500_title');
            $t500Heading = trans('errors.500_heading');
            $t500Body = trans('errors.500_body');
        }
    } catch (\Throwable) {
    }
@endphp
@section('title', $t500Title)
@section('heading', $t500Heading)
@section('message', $t500Body)
