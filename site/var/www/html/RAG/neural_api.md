# neural-api — ficha tecnica RAG

## Identificacao
Repositorio: marcelomaurin/neural-api
Fonte: documentacao do proprio repositorio.

## Documentacao, funcionalidades e aspectos tecnicos

# CAI NEURAL API [![VERSION](https://img.shields.io/github/v/release/joaopauloschuler/neural-api)](https://github.com/joaopauloschuler/neural-api/releases)
<img align="right" src="docs/cai.png" height="192">

**Everyone says Pascal died in the 90s.** Meanwhile, this Pascal library runs GPT-style transformers, Mixture-of-Experts, RWKV, xLSTM, spiking neurons, and normalizing flows, natively, with no Python nor CUDA lock-in. Your PyTorch project: 4GB of dependencies, a conda environment that breaks monthly. This: **compile to a single native binary with AVX**. This is deep learning the way systems programmers wish it worked.

**And it actually runs the real thing.** Point the [`ChatTerminal`](examples/ChatTerminal) example at a HuggingFace instruct model — Qwen2.5 (0.5B up to 32B), Qwen3, Llama (TinyLlama, SmolLM2), Mistral 7B, Phi-3-mini or the OLMoE Mixture-of-Experts — and chat with a genuine LLM in your terminal: **OpenCL-accelerated**, **no Python**, **no CUDA** — with more architectures landing all the time. Then hand a text prompt to [`MusicGenText`](examples/MusicGenText) and watch the *same library* turn *"lo-fi hip-hop with a mellow piano"* into actual audio — T5 encoder and MusicGen decoder. One native binary, from a 20-line CIFAR demo to a billion-parameter transformer.

CAI NEURAL API is a Pascal deep-learning library optimized for the AVX, AVX2 and AVX512 instruction sets and for OpenCL devices from AMD, Intel and NVIDIA. Tested on Windows and Linux.

## Highlights
* 🗣️ **Run real LLMs, natively** — import a HuggingFace instruct checkpoint (Qwen2.5/Qwen3, Llama, Mistral, Phi-3, OLMoE) and chat from your terminal with [`ChatTerminal`](examples/ChatTerminal). No Python, no CUDA.
* 🎵 **Text-to-music** — turn a text prompt into audio with [`MusicGenText`](examples/MusicGenText) (T5 encoder + MusicGen decoder).
* 🔊 **More audio** — separate a song into stems with [`MusicSourceSeparation`](examples/MusicSourceSeparation), or talk to an audio-aware model with [`Qwen2AudioChat`](examples/Qwen2AudioChat).
* 🧠 **Modern architectures, built in** — GPT-style transformers, Mixture-of-Experts, RWKV, xLSTM, spiking neurons and normalizing flows, all as native Pascal layers.
* 🖼️ **Image super-resolution** — upscale images with a trained network via the [`SuperResolution`](examples/SuperResolution) command-line tool (ships with a ready-to-use model).
* ⚡ **Fast everywhere** — AVX/AVX2/AVX512 CPU paths plus OpenCL offload for AMD, Intel and NVIDIA GPUs.
* 📦 **Single-binary deploy** — compile to one self-contained native executable. No runtime, no dependency tree.
* 📖 **Readable at native speed** — clean, easy-to-learn Pascal that compiles to fast native code. No trade-off between readable source and raw performance.

## Prerequisites
You'll need [Lazarus](https://www.lazarus-ide.org/) development environment. If you have an OpenCL capable device, you'll need its OpenCL drivers. Many examples use the [CIFAR-10](https://www.cs.toronto.edu/~kriz/cifar.html) dataset. You'll also find examples for the [CIFAR-100](https://www.cs.toronto.edu/~kriz/cifar.html), [MNIST](http://yann.lecun.com/exdb/mnist/), [Fashion MNIST](https://www.kaggle.com/zalando-research/fashionmnist) and the [Places365-Standard Small images 256x256](http://places2.csail.mit.edu/download.html) dataset.

## Will It Work with Delphi?
This project is [Lazarus](https://www.lazarus-ide.org/) / [Free Pascal](https://www.freepascal.org/) based. The master branch does not work with Delphi. For Delphi, use [v2.0.0](https://github.com/joaopauloschuler/neural-api/releases/tag/v2.0.0) instead.

## Installation
Clone this project, add the [**neural**](https://github.com/joaopauloschuler/neural-api/tree/master/neural) folder to your [Lazarus](https://www.lazarus-ide.org/) unit search path and you'll be ready to go!

## A.I. Powered Support
You can get A.I. powered help from these tools:
* [CAI Neural API support at Poe (free)](https://poe.com/CAI-NEURAL-API-FREE)
* [CAI Neural API support at Poe](https://poe.com/CAI-NEURAL-API)
* [CAI Neural API support at ChatGPT4](https://chatgpt.com/g/g-6BrAwhTQ9-free-pascal-developer-neural-api)
 
## Documentation
The documentation covers: 
* [Easy examples](#easy-examples-first-please)
* [Simple image classification examples](#simple-image-classification-examples)
* [Youtube videos](#youtube-videos)
* [Advanced examples](#advanced-examples)
* [Data structures (Volumes)](#volumes)
* [Neural network layers](#neural-network-layers)
* [Dataset support](#dataset-support)
* [Training (fitting) your neural network](#fitting-your-neural-network)
* [Parallel computing](#parallel-computing---the-neuralthreadpas)
* [Full set of examples](examples/README.md)
* [Normalization Cheat Sheet](docs/normalization.md)
* [Layer Authoring Guide](docs/layer-authoring.md) — checklist for adding a new layer plus mini-guides on reading numerical-gradient failures and picking a tolerance
* [Other scientific publications from the same author](#publications-from-the-author)

## Intro Videos
[![Watch the video](https://img.youtube.com/vi/aIy1S7clhQo/0.jpg)](https://youtu.be/aIy1S7clhQo) | [![Watch the video](https://img.youtube.com/vi/q56NcgUiAAk/0.jpg)](https://youtu.be/q56NcgUiAAk) | [![Watch the video](https://img.youtube.com/vi/PdNTgI_qSyo/0.jpg)](https://youtu.be/PdNTgI_qSyo)
--------------------------- | ------------------------------------- | -------------------------
Basics of Neural Networks in Pascal - Loading and Saving | Neural Networks for Absolute Beginners! Learning a Simple Function | Coding a Neural Network in Pascal that Learns to Calculate the Hypotenuse

### Easy Examples First Please!
[![Watch the video](https://img.youtube.com/vi/PdNTgI_qSyo/0.jpg)](https://youtu.be/PdNTgI_qSyo)

You can click on the image above to watch the video.

Assuming that you would like to train a neural network to learn a function that has 2 inputs and one output, you could start with something like this:
```
    NN.AddLayer([
      TNNetInput.Create(2),
      TNNetFullConnectReLU.Create(32),
      TNNetFullConnectReLU.Create(32),
      TNNetFullConnectLinear.Create(1)
    ]);
```
The example above has 2 inputs (`TNNetInput`), 2 dense layers (`TNNetFullConnectReLU`) with 32 neurons each and one output (`TNNetFullConnectLinear`).

You can learn more about how to build and train simple neural networks at the following source code examples:
* [Only one neuron](https://github.com/joaopauloschuler/neural-api/tree/master/examples/OnlyOneNeuron)
* [Training a neural network to learn the hypotenuse function](https://github.com/joaopauloschuler/neural-api/tree/master/examples/Hypotenuse)
* [Training a neural network to learn the hypotenuse function with FitLoading](https://github.com/joaopauloschuler/neural-api/tree/master/examples/HypotenuseFitLoading)
* [Training a neural network to learn boolean functions AND, OR and XOR with neuralfit unit](https://github.com/joaopauloschuler/neural-api/tree/master/examples/XorAndOr)
* [Training a neural network to learn boolean functions AND, OR and XOR without neuralfit unit](https://sourceforge.net/p/cai/svncode/HEAD/tree/trunk/lazarus/experiments/supersimple/supersimple.lpr)
* [Reptile first-order meta-learning: learning an initialization that adapts to a new sine-regression task in a few SGD steps](https://github.com/joaopauloschuler/neural-api/tree/master/examples/MetaLearningReptile)
* [Lottery Ticket Hypothesis: magnitude-prune a small dense net, then retrain the sparse mask from the original init vs from fresh random weights — the original-init "winning ticket" matches the dense net and beats random reinit at moderate-to-high sparsity (pure CPU)](https://github.com/joaopauloschuler/neural-api/tree/master/examples/LotteryTicket)

### Loading and Saving Neural Networks
Loading is very easy:
```
    NN := TNNet.Create;
    NN.LoadFromFile('MyTrainedNeuralNetwork.nn');
```
Saving is as easy:

```
    NN.SaveToFile('MyTrainedNeuralNetwork.nn');
```

### Simple Image Classification Examples

#### CIFAR-10 Image Classification Example
The CIFAR-10 dataset is a well-known collection of images commonly used to train machine learning and computer vision algorithms. It was created by the Canadian Institute for Advanced Research (CIFAR). It contains 60K 32x32 color images. The images are classified into 10 different classes, with 6,000 images per class. The classes represent airplanes, cars, birds, cats, deer, dogs, frogs, horses, ships, and trucks. Despite its relatively low resolution and small size, CIFAR-10 can be challenging for models to achieve high accuracy, making it a good dataset for testing advancements in machine learning techniques.

Follows a source code example for the CIFAR-10 image classification:
```
NN := TNNet.Create();
NN.AddLayer([
  TNNetInput.Create(32, 32, 3), //32x32x3 Input Image
  TNNetConvolutionReLU.Create({Features=}16, {FeatureSize=}5, {Padding=}0, {Stride=}1, {SuppressBias=}0),
  TNNetMaxPool.Create({Size=}2),
  TNNetConvolutionReLU.Create({Features=}32, {FeatureSize=}5, {Padding=}0, {Stride=}1, {SuppressBias=}0),
  TNNetMaxPool.Create({Size=}2),
  TNNetConvolutionReLU.Create({Features=}32, {FeatureSize=}5, {Padding=}0, {Stride=}1, {SuppressBias=}0),
  TNNetFullConnectReLU.Create({Neurons=}32),
  TNNetFullConnectLinear.Create(NumClasses),
  TNNetSoftMax.Create()
]);

CreateCifar10Volumes(ImgTrainingVolumes, ImgValidationVolumes, ImgTestVolumes);

WriteLn('Neural Network will minimize error with:');
WriteLn(' Layers: ', NN.CountLayers());
WriteLn(' Neurons:', NN.CountNeurons());
WriteLn(' Weights:', NN.CountWeights());

NeuralFit := TNeuralImageFit.Create;
NeuralFit.InitialLearningRate := fLearningRate;
NeuralFit.Inertia := fInertia;
NeuralFit.Fit(NN, ImgTrainingVolumes, ImgValidationVolumes, ImgTestVolumes, NumClasses, {batchsize}128, {epochs}100);
 ```

These examples train a neural network to classify images in classes such as: image has a cat, image has a dog, image has an airplane...
* [Simple CIFAR-10 Image Classifier](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SimpleImageClassifier)  [![Open In Colab](https://colab.research.google.com/assets/colab-badge.svg)](https://colab.research.google.com/github/joaopauloschuler/neural-api/blob/master/examples/SimpleImageClassifier/SimpleImageClassifierCPU.ipynb)
* [Simple CIFAR-10 Image Classifier with OpenCL](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SimpleImageClassifierGPU)  [![Open In Colab](https://colab.research.google.com/assets/colab-badge.svg)](https://colab.research.google.com/github/joaopauloschuler/neural-api/blob/master/examples/SimpleImageClassifierGPU/SimpleImageClassifierGPU.ipynb)
* [Many neural network architectures for CIFAR-10 image classification](https://sourceforge.net/p/cai/svncode/HEAD/tree/trunk/lazarus/experiments/testcnnalgo/testcnnalgo.lpr)
* [MNIST](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SimpleMNist), [Fashion MNIST](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SimpleFashionMNIST) and [CIFAR-100](https://github.com/joaopauloschuler/neural-api/tree/master/examples/Cifar100CaiDenseNet)

You can save and load trained models (neural networks) with `TNNet.SaveToFile` and `TNNet.LoadFromFile`. The file format is portable meaning that you can train on CPU and run on GPU or train in AMD and run on ARM as examples. The following code shows a simple example for image classification loading a [pre-trained](https://github.com/joaopauloschuler/pre-trained-neural-api-networks/) model:
```
  procedure ClassifyOneImageSimple;
  var
    NN: TNNet;
    ImageFileName: string;
    NeuralFit: TNeuralImageFit;
  begin
    WriteLn('Loading Neural Network...');
    NN := TNNet.Create;
    NN.LoadFromFile('SimplePlantLeafDisease-20230720.nn');
    NeuralFit := TNeuralImageFit.Create;
    ImageFileName := 'plant/Apple___Black_rot/image (1).JPG';
    WriteLn('Processing image: ', ImageFileName);
    WriteLn(
      'The class of the image is: ',
      NeuralFit.ClassifyImageFromFile(NN, ImageFileName)
    );
    NeuralFit.Free;
    NN.Free;
  end;  
```

### Youtube Videos
[![Watch the video](https://img.youtube.com/vi/aIy1S7clhQo/0.jpg)](https://youtu.be/aIy1S7clhQo) | [![Watch the video](https://img.youtube.com/vi/q56NcgUiAAk/0.jpg)](https://youtu.be/q56NcgUiAAk) | [![Watch the video](https://img.youtube.com/vi/PdNTgI_qSyo/0.jpg)](https://youtu.be/PdNTgI_qSyo)
--------------------------- | ------------------------------------- | -------------------------
Basics of Neural Networks in Pascal - Loading and Saving | Neural Networks for Absolute Beginners! Learning a Simple Function | Coding a Neural Network in Pascal that Learns to Calculate the Hypotenuse
[![Watch the video](https://img.youtube.com/vi/tODsv6Ks2DM/0.jpg)](https://youtu.be/tODsv6Ks2DM) | [![Watch the video](https://img.youtube.com/vi/f4T9IB-He_k/0.jpg)](https://youtu.be/f4T9IB-He_k) | [![Watch the video](https://img.youtube.com/vi/o-8NuoSsdck/0.jpg)](https://youtu.be/o-8NuoSsdck)
Pre-trained Neural Networks & Transfer Learning with Pascal's CAI Neural API | Coding a Neural Network in Pascal that Learns the OR Boolean Operation | A Dive into Identity Shortcut Connection - The ResNet building block
[![Watch the video](https://img.youtube.com/vi/SEvWB7k8uy0/0.jpg)](https://youtu.be/SEvWB7k8uy0) | [![Watch the video](https://img.youtube.com/vi/3QwIaAsDmJw/0.jpg)](https://youtu.be/3QwIaAsDmJw) | [![Watch the video](https://img.youtube.com/vi/VH6v3D5cxxs/0.jpg)](https://youtu.be/VH6v3D5cxxs)
Increasing Image Resolution with Neural Networks | Ultra Fast Single Precision Floating Point Computing | AVX and AVX2 Code Optimization

Some videos make referrence to **uvolume** unit. The current **neuralvolume** unit used to be called **uvolume**. This is why
it's mentioned.

### Advanced Examples
Although these examples require deeper understanding about neural networks, they are very interesting:
* [Identity Shortcut Connection](https://github.com/joaopauloschuler/neural-api/tree/master/examples/IdentityShortcutConnection) - ResNet building block [![Open In Colab](https://colab.research.google.com/assets/colab-badge.svg)](https://colab.research.google.com/github/joaopauloschuler/neural-api/blob/master/examples/IdentityShortcutConnection/IdentityShortcutConnection.ipynb)
* [ResNet-20](https://github.com/joaopauloschuler/neural-api/blob/master/examples/ResNet/) - includes a [web server](examples/ResNet/server) example
* [DenseNetBC L40](https://github.com/joaopauloschuler/neural-api/tree/master/examples/DenseNetBCL40)
* [Separable Convolutions](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SeparableConvolution) - MobileNet building block
* [Gradient Ascent](https://github.com/joaopauloschuler/neural-api/tree/master/examples/GradientAscent) - Visualizing patterns from inner neurons in image classification <p><img src="https://github.com/joaopauloschuler/neural-api/blob/master/docs/gradientascent3layer.jpg" height="130"></img></p>
* [Artificial Art](https://github.com/joaopauloschuler/neural-api/tree/master/examples/VisualGAN) - Let a neural network produce art via a generative adversarial network <p><img src="https://github.com/joaopauloschuler/neural-api/blob/master/docs/art1.png" height="130"></img></p>
* [Super Resolution](https://github.com/joaopauloschuler/neural-api/tree/master/examples/SuperResolution) - A neural network learns how to increase image resolution<p><img src="examples/SuperResolution/results/building_result.png"></img></p>
* [CIFAR-10 Resized](https://github.com/joaopauloschuler/neural-api/tree/master/examples/Cifar10Resize) - A program that resizes CIFAR-10 and CIFAR-100 images to 64x64 and 128x128 pixels.<p><img src="https://github.com/joaopauloschuler/neural-api/blob/master/examples/SuperResolution/results/bird.png?raw=true"> </img></p><p><img src="https://github.com/joaopauloschuler/neural-api/blob/master/examples/SuperResolution/results/stealth.png?raw=true"> </img></p>
* [Autoencoder](https://github.com/joaopauloschuler/neural-api/tree/master/examples/VisualAutoencoder) - Shows an autoencoder built with hyperbolic tangents and trained with [Tiny ImageNet 200](https://paperswithcode.com/dataset/tiny-imagenet). <p><img src="docs/autoencoder_small.png"></img></p>

There is also a [full set of examples](examples/README.md) that you can look at.

## Volumes
Volumes behave like dynamically created arrays. They are the main array like structure used by this API. `TNNetVolume` class allows you to create volumes that can be accessed as 1D, 2D or 3D arrays and be operated with [Advanced Vector Extensions (AVX)](https://en.wikipedia.org/wiki/Advanced_Vector_Extensions) - [Single Instruction Multiple Data (SIMD)](https://en.wikipedia.org/wiki/Single_instruction,_multiple_data) instruction set. The usual way to create a volume is:
```
constructor Create(pSizeX, pSizeY, pDepth: integer; c: T = 0);
```
You can access the data as 1D or 3D with:
```
property Raw[x: integer]: T read GetRaw write SetRaw;
property Data[x, y, d: integer]: T read Get write Store; default;
```
Your code will look like this:
```
// Usage Examples
vInput := TNNetVolume.Create(32, 32, 3);
vInput[1, 1, 1] := 1;
vInput[2, 2, 2] := vInput[1, 1, 1] + 1;
vInput.Raw[10] := 5;

vInput.RandomizeGaussian();
WriteLn('Avg: ', vInput.GetAvg());
WriteLn('Variance: ', vInput.GetVariance());
WriteLn('Std Dev: ', vInput.GetStdDeviation());

WriteLn('Multiplying by 10');
vInput.Mul(10);
WriteLn('Avg: ', vInput.GetAvg());
WriteLn('Variance: ', vInput.GetVariance());
WriteLn('Std Dev: ', vInput.GetStdDeviation());
```
As examples, you can add, subtract, multiply and calculate dot products with:
```
procedure Add(Original: TNNetVolume); overload;
procedure Sub(Original: TNNetVolume); overload;
procedure Mul(Value: Single); overload;
function DotProduct(Original: TNNetVolume): TNeuralFloat; overload;
```
In the case that you need the raw position or raw po

## Como usar este documento
O JARVIS deve usar esta ficha para conversar sobre objetivo, linguagem, bibliotecas, hardware, protocolos, funcoes, arquitetura, instalacao e uso do projeto conforme os dados documentados. Nao inventar recursos ausentes.
