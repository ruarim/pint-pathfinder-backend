<?php

namespace App\Services;

use App\Models\Venue;
use Fisharebest\Algorithm\Dijkstra;
use Illuminate\Database\Eloquent\Builder;

class RouteSuggester
{
    private float $searchRange = 0.2; //needs tweaking later

    public function __construct(private array $stopsAttributes, private array $start, private array $end)
    {
    }

    public function suggest()
    {
        $stopsVenues = [];
        //for each stop -> get venue ids where attributes
        foreach ($this->stopsAttributes as $attributes) {
            $matches = $this->getLocalVenuesByAttributes($attributes, $this->start, $this->end, $this->searchRange);
            array_push($stopsVenues, $matches);
        }

        $venuesGraph = $this->createVenuesGraph($stopsVenues);
        $dijkstra = new Dijkstra($venuesGraph);

        $path = $dijkstra->shortestPaths('start', 'end');

        dd($path);
    }

    private function getLocalVenuesByAttributes(array $attributes, array $start, array $end, int $searchRange)
    {
        // loop here

        $lat = $this->sort($start[0], $end[0]);
        $long = $this->sort($start[1], $end[1]);

        //get local venues with stop attributes
        $matches =
            Venue::where(
                function (Builder $query) use ($lat, $long, $searchRange, $attributes) {
                    $query
                        ->whereHas('address', function (Builder $query) use ($lat, $long, $searchRange) {
                            $query
                                ->where('latitude', '>', $lat[0] - $searchRange)
                                ->where('latitude', '<', $lat[1] + $searchRange)
                                ->where('longitude', '>', $long[0])
                                ->where('longitude', '<', $long[1]);
                        })
                        ->whereHas('attributes', function (Builder $query) use ($attributes) {
                            $query
                                ->whereIn('name', $attributes);
                        }, '>=', count($attributes));
                }
            )
            ->join('addresses', 'venues.id', '=', 'addresses.venue_id')
            ->get();

        return $matches;
    }

    private function createVenuesGraph(array $stops)
    {
        //so longer route have lower total distance
        $distanceOffset = 0.02;
        $numberOfStops = count($stops);
        $graph = array();

        $start_vertices = array();
        foreach ($stops[0] as $venue) {
            $distance = $this->calculateDistance(
                $this->start,
                [$venue->latitude, $venue->longitude]
            );
            $id = $venue->id;
            $start_vertices[$id] = $distance - $distanceOffset;
        }
        $graph['start'] = $start_vertices;

        //last venues adj to end
        foreach ($stops[$numberOfStops - 1] as $venue) {
            $distance = $this->calculateDistance(
                array($venue->latitude, $venue->longitude),
                $this->end
            );
            $id = $venue->id;
            $graph[$id] = ['end' => $distance - $distanceOffset];
        }

        //else current stop vertices adj to next stop vertices
        foreach ($stops as $key => $stopVenues) {
            if ($key == $numberOfStops - 1) break;

            foreach ($stopVenues as $venue) {
                $id = $venue->id;
                if (array_key_exists($id, $graph)) continue;

                $vertices = array();
                foreach ($stops[$key + 1] as $nextVenue) {
                    $distance = $this->calculateDistance(
                        array($venue->latitude, $venue->longitude),
                        array($nextVenue->latitude, $nextVenue->longitude)
                    );
                    if ($distance == 0) continue;
                    $id = $nextVenue->id;
                    $vertices[$id] = $distance - $distanceOffset;
                }
                //if key doesnt exist
                $id = $venue->id;
                $graph[$id] = $vertices;
            }
        }
        $graph['end'] = [];
        return $graph;
    }

    private function sort(float $start, float $end)
    {
        return ($start < $end) ? [$start, $end] : [$end, $start];
    }

    private function calculateDistance(array $current, array $next)
    {
        return sqrt(pow(abs($next[1] - $current[1]), 2) + pow(abs($next[0] - $current[0]), 2));
    }
}
