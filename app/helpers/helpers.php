<?php

if (! function_exists('apiResponse')) {
    function apiResponse($data = null, $status = 200, $msg = '')
    {
        return response()->json([
            'data' => $data,
            'status' => $status,
            'msg' => $msg,
        ], $status);
    }
}
