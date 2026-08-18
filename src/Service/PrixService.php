<?php

namespace App\Service;

use App\Repository\PlafondPrixRepository;

/**
 * Calcul des prix avec plafond anti-abus (point 7 du cahier des charges).
 *
 * - Distance : haversine à partir des coordonnées GPS du trajet si disponibles,
 *   sinon table de distances connues, sinon défaut 200 km.
 * - Prix conseillé : (distance × taux) / places, arrondi à la centaine, min 500 FCFA.
 * - Plafond : valeur du tableau PlafondPrix si elle existe, sinon distance × 20 FCFA/km.
 *   Le prix demandé par le conducteur ne peut jamais dépasser ce plafond.
 */
class PrixService
{
    private const TAUX_FCFA_PAR_KM = 30.0;
    private const TAUX_PLAFOND_FCFA_PAR_KM = 20.0;
    private const PRIX_MINIMUM = 500.0;
    private const DISTANCE_DEFAUT = 200.0;

    /** Distances approximatives (km) entre grandes villes du Cameroun. */
    private const DISTANCES_CONNUES = [
        'Yaoundé-Douala' => 250, 'Douala-Yaoundé' => 250,
        'Yaoundé-Bafoussam' => 290, 'Bafoussam-Yaoundé' => 290,
        'Douala-Bafoussam' => 220, 'Bafoussam-Douala' => 220,
        'Yaoundé-Bamenda' => 380, 'Bamenda-Yaoundé' => 380,
        'Douala-Bamenda' => 340, 'Bamenda-Douala' => 340,
        'Yaoundé-Garoua' => 650, 'Garoua-Yaoundé' => 650,
        'Douala-Kribi' => 170, 'Kribi-Douala' => 170,
        'Yaoundé-Ebolowa' => 130, 'Ebolowa-Yaoundé' => 130,
        'Douala-Limbe' => 70, 'Limbe-Douala' => 70,
        'Douala-Buea' => 70, 'Buea-Douala' => 70,
        'Yaoundé-Kribi' => 260, 'Kribi-Yaoundé' => 260,
        'Yaoundé-Bertoua' => 380, 'Bertoua-Yaoundé' => 380,
        'Douala-Ngaoundéré' => 600, 'Ngaoundéré-Douala' => 600,
        'Yaoundé-Ngaoundéré' => 550, 'Ngaoundéré-Yaoundé' => 550,
        'Yaoundé-Maroua' => 850, 'Maroua-Yaoundé' => 850,
        'Douala-Maroua' => 900, 'Maroua-Douala' => 900,
    ];

    private PlafondPrixRepository $plafondRepository;

    public function __construct(PlafondPrixRepository $plafondRepository)
    {
        $this->plafondRepository = $plafondRepository;
    }

    /** Distance en km entre deux points GPS (formule haversine). */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rayonTerreKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $rayonTerreKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Distance estimée entre deux villes (GPS si possible, sinon table connue, sinon défaut). */
    public function distanceEntre(
        string $villeDepart,
        string $villeArrivee,
        ?float $latDepart = null,
        ?float $lngDepart = null,
        ?float $latArrivee = null,
        ?float $lngArrivee = null
    ): float {
        if ($latDepart !== null && $lngDepart !== null && $latArrivee !== null && $lngArrivee !== null) {
            $distance = $this->distanceKm($latDepart, $lngDepart, $latArrivee, $lngArrivee);
            return max(5.0, round($distance, 1));
        }

        $cle = trim($villeDepart) . '-' . trim($villeArrivee);
        if (isset(self::DISTANCES_CONNUES[$cle])) {
            return (float) self::DISTANCES_CONNUES[$cle];
        }

        return self::DISTANCE_DEFAUT;
    }

    /** Plafond (FCFA/place) pour une paire de villes, avec repli automatique. */
    public function plafondPour(string $villeDepart, string $villeArrivee, float $distanceKm): float
    {
        $plafond = $this->plafondRepository->trouverPlafond($villeDepart, $villeArrivee);

        if ($plafond !== null) {
            return max(self::PRIX_MINIMUM, (float) $plafond->getPrixMax());
        }

        return max(self::PRIX_MINIMUM, round($distanceKm * self::TAUX_PLAFOND_FCFA_PAR_KM / 100) * 100);
    }

    /**
     * Calcul complet : prix conseillé + prix maximum + plafond appliqué.
     *
     * @return array{distance: float, coutTotal: float, prixConseille: float, prixMax: float, plafondApplique: string}
     */
    public function calculer(
        string $villeDepart,
        string $villeArrivee,
        int $nbPlaces = 1,
        ?float $latDepart = null,
        ?float $lngDepart = null,
        ?float $latArrivee = null,
        ?float $lngArrivee = null
    ): array {
        $distance = $this->distanceEntre($villeDepart, $villeArrivee, $latDepart, $lngDepart, $latArrivee, $lngArrivee);

        $coutTotal = $distance * self::TAUX_FCFA_PAR_KM;
        $prixConseille = round($coutTotal / max(1, $nbPlaces) / 100) * 100;
        $prixConseille = max(self::PRIX_MINIMUM, (float) $prixConseille);

        $prixMax = $this->plafondPour($villeDepart, $villeArrivee, $distance);
        if ($prixConseille > $prixMax) {
            $prixConseille = $prixMax;
        }

        $plafondSpecifique = $this->plafondRepository->trouverPlafond($villeDepart, $villeArrivee);

        return [
            'distance' => round($distance, 1),
            'coutTotal' => round($coutTotal),
            'prixConseille' => $prixConseille,
            'prixMax' => $prixMax,
            'plafondApplique' => $plafondSpecifique !== null ? 'specifique' : 'par_defaut',
        ];
    }

    /** Vérifie qu'un prix par place respecte le plafond de la route. */
    public function verifierPrixPlafond(float $prixParPlace, float $prixMax): bool
    {
        return $prixParPlace <= $prixMax;
    }
}
