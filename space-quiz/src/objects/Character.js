import * as THREE from "three";

export class Character {

    constructor() {

        this.mesh = new THREE.Group();

        const body = new THREE.Mesh(

            new THREE.CapsuleGeometry(
                0.5,
                1.5,
                4,
                8
            ),

            new THREE.MeshStandardMaterial({
                color: 0xffffff
            })

        );

        this.mesh.add(body);

        this.mesh.userData.type =
            "character";
    }

}
