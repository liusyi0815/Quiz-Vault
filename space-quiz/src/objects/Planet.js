import * as THREE from "three";

export class Planet {
    constructor(name, color, x, radius = 3, textureUrl = null) {
        this.name = name;

        const geometry = new THREE.SphereGeometry(radius, 64, 64);
        
        let material;
        if (textureUrl) {
            const textureLoader = new THREE.TextureLoader();
            const texture = textureLoader.load(textureUrl);
            // 確保材質支援 emissive 發光效果
            material = new THREE.MeshStandardMaterial({
                map: texture,
                roughness: 0.7,
                metalness: 0.1,
                emissive: 0x000000, // 預設不發光
                emissiveIntensity: 0.5
            });
        } else {
            material = new THREE.MeshStandardMaterial({ 
                color: color,
                roughness: 0.7,
                metalness: 0.1,
                emissive: 0x000000,
                emissiveIntensity: 0.8 // 預設發光強度
            });
        }

        this.mesh = new THREE.Mesh(geometry, material);
        this.mesh.position.x = x;
        this.mesh.userData.type = "planet";
        this.mesh.userData.name = name;
    }
}
