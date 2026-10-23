@extends('errors.layout')
@section('code', '401')
@section('title', __('errors.401_title'))
@section('heading', __('errors.401_heading'))
@section('message', __('errors.401_body'))
@section('actions')
    <a class="btn" href="/login">{{ __('errors.sign_in') }}</a>
@endsection
