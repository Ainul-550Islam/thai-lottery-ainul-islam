@extends('errors.layout')
@section('code', '503')
@php
    $t503Title = '503 — Service Unavailable';
    $t503Heading = 'Service Temporarily Unavailable';
    $t503Body = 'Scheduled maintenance or temporary capacity protection is active. Please try again shortly.';
    try {
        if (function_exists('app') && app()->bound('translator')) {
            $t503Title = trans('errors.503_title');
            $t503Heading = trans('errors.503_heading');
            $t503Body = trans('errors.503_body');
        }
    } catch (\Throwable) {
    }
@endphp
@section('title', $t503Title)
@section('heading', $t503Heading)
@section('message', $t503Body)
