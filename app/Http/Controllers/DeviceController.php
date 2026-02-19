<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //Usamos 'with' para traer la relacion con 'status' para evitar el problema de N+1 consultas
        $devices = Device::with('status')->paginate(20); //Paginamos de 20 en 20
        return response()->json($devices);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //1. Validacion (Evita basrua en la DB)
        $validated = $request->validate([
            'imei' => 'required|unique:devices,imei',
            'brand' => 'required|string',
            'model' => 'required|string',
            'name' => 'nullable|string',
            'status_id' => 'required|exists:status,id'
        ]);

        //2. Crear registro
        //El UUID se genera solo en la base de datos, no es necesario asignarlo aquí
        $device = Device::create($validated);
        return response()->json([
            'message' => 'Device created successfully',
            'data' => $device
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $device = Device::with(['status', 'telemetry'])->find($id);
        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }
        return response()->json($device);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        //Validacion (Similar a store pero con reglas de unicidad adaptadas para update)
        $validatedData = $request->validate([
            'imei' => 'required|unique:devices,imei,' . $device->id,
            'name' => 'nullable|string',
            'frequency' => 'integer|min:10'
        ]);
        $device->update($validatedData);

        return response()->json([
            'message' => 'Device updated successfully',
            'data' => $device
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }
        $device->delete();
        return response()->json(['message' => 'Device deleted successfully']);
    }
}
