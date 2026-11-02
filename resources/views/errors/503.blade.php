@extends('errors.layout')

@section('code', '503')
@section('title', 'Down for maintenance')
{{--
    The office can write its own message in Settings, Maintenance. When
    it has not, the original sentence below is what visitors read, so
    this page is unchanged for every other kind of 503.
--}}
@section('message', $maintenanceMessage ?? 'Visit Dasol is being updated. Please check back shortly.')
