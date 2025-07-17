<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class DoctorController extends Controller
{
    // Get all doctors for the current company with full image URL
    public function index()
    {
        $user = Auth::user();

        $doctors = Doctor::with(['user:id,name'])
            ->where('company_id', $user->company_id)
            ->get()
            ->map(function ($d) {
                $d->image = $d->image ? $d->image : null; // already full URL
                return $d;
            });

        return response()->json([
            "doctors" => $doctors,
            "status" => "success",
        ], 200);
    }

    // Create new doctor and upload image to cloud storage
    public function store(Request $request)
    {
        $user = Auth::user();

        $validate = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'speciatly' => 'required|string|max:255',
            'email' => 'required|email|unique:doctors,email',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'status' => 'required|in:available,on leave',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'status' => 422,
                'message' => $validate->errors()->first(),
            ]);
        }

        $doctor = new Doctor();
        $doctor->name = $request->name;
        $doctor->user_id = $user->id;
        $doctor->company_id = $user->company_id;
        $doctor->speciatly = $request->speciatly;
        $doctor->email = $request->email;
        $doctor->status = $request->status;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fileName = uniqid('doctor_') . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('doctors', $fileName, 's3'); // uploads to cloud
            $url = Storage::disk('s3')->url($path); // get public URL
            $doctor->image = $url; // store full image URL
        }

        $doctor->save();

        return response()->json([
            'message' => 'Doctor created successfully',
            'doctor' => $doctor,
        ], 201);
    }

    // Delete doctor and remove image from cloud storage
    public function destroy($id)
    {
        $doctor = Doctor::find($id);

        if (!$doctor) {
            return response()->json([
                'message' => 'Doctor not found',
                'status' => 404
            ], 404);
        }

        // Remove image from cloud
        if ($doctor->image) {
            $path = parse_url($doctor->image, PHP_URL_PATH);
            $path = ltrim($path, '/');
            Storage::disk('s3')->delete($path);
        }

        $doctor->delete();

        return response()->json([
            'message' => 'Doctor deleted successfully',
            'status' => 200
        ], 200);
    }
}